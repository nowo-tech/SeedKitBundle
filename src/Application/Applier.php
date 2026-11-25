<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Event\SeedBatchCommittedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunAppliedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunFailedEvent;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\TransactionContract\ConcurrencyConflict;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_keys;
use function array_reverse;
use function count;
use function usort;

/**
 * Applies a planned run (contract section 10.2 step 4, 10.3): takes the lease of every (provider, scope) in name
 * order, re-plans the whole run and refuses a stale plan (`SEED_005`), records the run, then applies the batches —
 * upserts in dependency order, deletes in reverse order — each in its own write unit on the bundle's connection: the
 * slice is re-planned with the provider's write lock and must match the reviewed batch digest (`SEED_006`), the
 * provider writes through the host's handlers, and the run's progress is recorded in the same transaction (a replay
 * never applies a batch twice). Leases are released in `finally`; events and audit come after the last commit.
 *
 * A plan without changes writes nothing at all (no run row, no transaction): re-applying a file is a no-op (SC-002).
 * Stateless; everything per run lives in local variables.
 */
final readonly class Applier
{
    /**
     * Builds the applier.
     */
    public function __construct(
        private Planner $planner,
        private WriteRunnerInterface $writer,
        private LeaseStoreInterface $leases,
        private RunStoreInterface $runs,
        private IdGeneratorInterface $ids,
        private SeedKitSettings $settings,
        private ClockInterface $clock,
        private ?LoggerInterface $logger,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    /**
     * Applies the run.
     *
     * @param list<PreparedDocument> $documents from {@see Planner::prepare()}
     * @param PlanHash|null $expected the reviewed plan hash (null: the re-plan is the reviewed plan, e.g. `--yes` without `--expect-hash`)
     *
     * @throws LeaseHeldException when another run holds a lease (nothing written)
     * @throws StalePlanException when the re-plan differs from `$expected` (nothing written)
     * @throws ApplyInterruptedException when the run stopped after it was created
     */
    public function apply(DocumentSet $set, array $documents, SeedContext $context, PruneOptions $prune, ?PlanHash $expected): ApplyResult
    {
        $runId = $this->ids->runId();
        $names = [];
        foreach ($documents as $document) {
            $names[] = $document->provider->name();
        }
        usort($names, static fn (ProviderName $a, ProviderName $b): int => $a->value <=> $b->value);
        $held = [];
        try {
            foreach ($names as $name) {
                $left = $this->leases->acquire($name, $context->scope, $runId, $this->settings->leaseSeconds);
                if ($left > 0) {
                    throw new LeaseHeldException($left);
                }
                $held[] = $name;
            }

            $planContext = new SeedContext($context->scope, $context->actor, $context->channel, null, new PendingKeys(), $prune->prune, $context->canSeeCurrentValues);
            $plan        = $this->planner->plan($documents, $planContext, $prune);
            if ($expected instanceof PlanHash && !$plan->hash->equals($expected)) {
                throw new StalePlanException();
            }
            if ($plan->changes() === 0) {
                return new ApplyResult($plan, null, 0);
            }

            return $this->run($set, $documents, $context, $prune, $plan, $runId, $held);
        } finally {
            foreach ($held as $name) {
                $this->release($name, $context, $runId);
            }
        }
    }

    /**
     * Records the run and applies its batches.
     *
     * @param list<PreparedDocument> $documents
     * @param list<ProviderName> $held
     *
     * @throws ApplyInterruptedException
     */
    private function run(DocumentSet $set, array $documents, SeedContext $context, PruneOptions $prune, SeedPlan $plan, RunId $runId, array $held): ApplyResult
    {
        $digests   = [];
        $writes    = [];
        $providers = [];
        $flat      = [];
        foreach ($plan->documents as $document) {
            $digests[$document->provider->value]   = [...$document->upsertDigests, ...$document->deleteDigests];
            $writes[$document->provider->value]    = $document->writes;
            $providers[$document->provider->value] = $document->version;
            foreach ($digests[$document->provider->value] as $digest) {
                $flat[] = $digest;
            }
        }
        $total  = $plan->writingBatches();
        $counts = [ChangeOp::Create->value => 0, ChangeOp::Update->value => 0, ChangeOp::Delete->value => 0];
        $record = new RunRecord($runId, $context->scope, $context->actor, $context->channel, RunStatus::Running, $providers, $prune->prune, $set->hash, $plan->hash, $flat, 0, $counts, null, $this->clock->now());
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $this->writer->write(function () use ($record): null {
            $this->runs->create($record);

            return null;
        });

        $runContext = new SeedContext($context->scope, $context->actor, $context->channel, $runId, new PendingKeys(), $prune->prune, $context->canSeeCurrentValues);
        $done       = 0;
        $step       = new BatchStep($runId, $total, $held);
        try {
            $seenByProvider = [];
            foreach ($documents as $document) {
                $name   = $document->provider->name();
                $seen   = new NaturalKeySet();
                $errors = new ErrorBag($this->settings->maxErrors);
                $number = 0;
                foreach ($this->planner->slices($document, $runContext, $seen, $errors) as $slice) {
                    ++$number;
                    if (!($writes[$name->value][$number - 1] ?? false)) {
                        continue;
                    }
                    $items = [];
                    foreach ($slice as $item) {
                        $items[$item->key->canonical] = $item;
                    }
                    $replan = fn (PlanOptions $options, ErrorBag $bag): array => $this->planner->planSlice($document, $runContext, $slice, $options, $bag);
                    $this->batch($step, $document, $runContext, $number, $digests[$name->value][$number - 1], $replan, $items, $counts, $done);
                }
                $errors->throwIfAny();
                $seenByProvider[$name->value] = [$seen, $number];
            }
            if ($prune->prune) {
                foreach (array_reverse($documents) as $document) {
                    $name           = $document->provider->name();
                    [$seen, $first] = $seenByProvider[$name->value];
                    $slices         = count($digests[$name->value]) - $first;
                    for ($slice = 1; $slice <= $slices; ++$slice) {
                        // The earlier deletion slices are committed, so the planned slice N is now the first one.
                        $replan = function (PlanOptions $options) use ($document, $runContext, $seen): array {
                            foreach ($this->planner->deletionSlices($document, $runContext, $seen, $options) as $changes) {
                                return $changes;
                            }

                            return [];
                        };
                        $this->batch($step, $document, $runContext, $first + $slice, $digests[$name->value][$first + $slice - 1], $replan, [], $counts, $done);
                    }
                }
            }
        } catch (ApplyInterruptedException $e) {
            $this->fail($runId, $e);

            throw $e;
        } catch (ConcurrencyConflict $e) {
            $interrupted = new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $done, $total, $runId->value, $e->retryAfterSeconds(), $e);
            $this->fail($runId, $interrupted);

            throw $interrupted;
        } catch (Throwable $e) {
            // Class and code only: driver messages quote row values (personal data, contract 13.1 / 14).
            $this->logger?->error('Seed run {run} failed in a provider: {exception_class} ({exception_code}).', ['run' => $runId->value, 'exception_class' => $e::class, 'exception_code' => (string) $e->getCode()]);
            $interrupted = new ApplyInterruptedException(ApplyInterruptedException::PROVIDER_FAILED, $done, $total, $runId->value, null, $e);
            $this->fail($runId, $interrupted);

            throw $interrupted;
        }
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $this->writer->write(function () use ($runId): null {
            $this->runs->finish($runId, RunStatus::Applied, null);

            return null;
        });
        $this->dispatcher?->dispatch(new SeedRunAppliedEvent($runId, array_keys($providers), $context->scope, $counts, $set->hash, $plan->hash, $context->actor, $context->channel));

        return new ApplyResult($plan, $runId, $done, $counts);
    }

    /**
     * Applies one writing batch in its own write unit: re-plan with the write lock, digest check, provider write, run
     * progress in the same transaction; then `$done` moves on, `afterBatch()`, lease renewal and the batch event.
     *
     * @param callable(PlanOptions, ErrorBag): list<RowChange> $replan
     * @param array<string, SeedItem> $items
     * @param array<string, int> $counts
     *
     * @throws ApplyInterruptedException
     */
    private function batch(BatchStep $step, PreparedDocument $document, SeedContext $context, int $number, string $digest, callable $replan, array $items, array &$counts, int &$done): void
    {
        $name     = $document->provider->name();
        $sequence = $done + 1;
        $before   = $done;
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        [$counts, $result, $changes] = $this->writer->write(function (int $attempt) use ($step, $document, $context, $number, $digest, $replan, $items, $sequence, $name, $counts, $before): array {
            if ($attempt > 1) {
                $document->provider->afterBatch($context);
            }
            $bag     = new ErrorBag($this->settings->maxErrors);
            $changes = $replan(new PlanOptions(true), $bag);
            if (!$bag->isEmpty() || PlanHasher::batchDigest($name, $number, $changes) !== $digest) {
                throw new ApplyInterruptedException(ApplyInterruptedException::ROW_CHANGED, $before, $step->total, $step->runId->value);
            }
            $writes = [];
            foreach ($changes as $change) {
                if ($change->writes()) {
                    $writes[] = $change;
                }
            }
            $result = $document->provider->applyBatch($context, new AppliedBatch($sequence, $writes, $items));
            $counts[ChangeOp::Create->value] += $result->created;
            $counts[ChangeOp::Update->value] += $result->updated;
            $counts[ChangeOp::Delete->value] += $result->deleted;
            if (!$this->runs->markBatch($step->runId, $sequence, $counts)) {
                // The run's progress moved on without us (a replayed or skipped batch): roll the unit back.
                throw new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $before, $step->total, $step->runId->value);
            }

            return [$counts, $result, $changes];
        });
        $done = $sequence;
        foreach ($changes as $change) {
            if ($change->op === ChangeOp::Create) {
                $context->pending->add($name, $change->key);
            }
        }
        $document->provider->afterBatch($context);
        foreach ($step->held as $lease) {
            if (!$this->leases->renew($lease, $context->scope, $step->runId, $this->settings->leaseSeconds)) {
                throw new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $done, $step->total, $step->runId->value, 1);
            }
        }
        $this->dispatcher?->dispatch(new SeedBatchCommittedEvent($step->runId, $name, $sequence, $result->created, $result->updated, $result->deleted));
    }

    /**
     * Marks the run failed or partially applied (best effort) and dispatches the failure event.
     */
    private function fail(RunId $runId, ApplyInterruptedException $e): void
    {
        try {
            // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
            $this->writer->write(function () use ($runId, $e): null {
                $this->runs->finish($runId, $e->batchesCommitted > 0 ? RunStatus::PartiallyApplied : RunStatus::Failed, $e->errorCode());

                return null;
            });
        } catch (Throwable $failure) {
            $this->logger?->warning('Seed run {run} could not be marked as failed: {exception_class}.', ['run' => $runId->value, 'exception_class' => $failure::class]);
        }
        $this->dispatcher?->dispatch(new SeedRunFailedEvent($runId, $e->errorCode(), $e->batchesCommitted));
    }

    /**
     * Releases a lease (best effort: it expires anyway).
     */
    private function release(ProviderName $name, SeedContext $context, RunId $runId): void
    {
        try {
            $this->leases->release($name, $context->scope, $runId);
        } catch (Throwable $e) {
            $this->logger?->warning('Seed lease of {provider} could not be released: {exception_class}.', ['provider' => $name->value, 'exception_class' => $e::class]);
        }
    }
}
