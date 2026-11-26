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
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
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
use Nowo\SeedKitBundle\Domain\Run\BatchManifest;
use Nowo\SeedKitBundle\Domain\Run\Channel;
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
 *
 * 0.2: {@see applyStored()} applies a run stored by the UI (claimed `running` by the caller): its batches are numbered
 * and checked against the stored batch layout ({@see BatchManifest}), batches up to `batches_done` are skipped (a
 * Messenger redelivery or a resume never applies a batch twice), and only a run that has not committed any batch is
 * compared with its reviewed plan hash (a resumed run relies on the per-batch digests, checked under the row locks).
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
            $manifest  = BatchManifest::fromPlan($plan);
            $counts    = [ChangeOp::Create->value => 0, ChangeOp::Update->value => 0, ChangeOp::Delete->value => 0];
            $providers = [];
            foreach ($plan->documents as $document) {
                $providers[$document->provider->value] = $document->version;
            }
            $record = new RunRecord($runId, $context->scope, $context->actor, $context->channel, RunStatus::Running, $providers, $prune->prune, $set->hash, $plan->hash, $manifest->flat(), 0, $counts, null, $this->clock->now(), null, $manifest);
            // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
            $this->writer->write(function () use ($record): null {
                $this->runs->create($record);

                return null;
            });

            return $this->run($set, $documents, $context, $prune, $plan, $record, $held, new RunClaim($runId, $runId, $record->version), false);
        } finally {
            foreach ($held as $name) {
                $this->release($name, $context, $runId);
            }
        }
    }

    /**
     * Applies a run the UI stored and the caller claimed (`running`): leases under the claim's holder, a heartbeat, the
     * stale check of a run that has not committed a batch yet (`$run->planHash`), another heartbeat, then the batches
     * of the stored layout after `batches_done`. Every write is fenced by `$claim` (ZCE-L1).
     *
     * @param list<PreparedDocument> $documents from {@see Planner::prepare()} over the stored document
     *
     * @throws LeaseHeldException when another run holds a lease (nothing written; the caller releases the claim)
     * @throws StalePlanException when the data changed since the review and no batch was committed (nothing written)
     * @throws ApplyInterruptedException when the run stopped after it started writing, or its claim was taken over
     */
    public function applyStored(RunRecord $run, RunClaim $claim, DocumentSet $set, array $documents, SeedContext $context, PruneOptions $prune): ApplyResult
    {
        if (!$run->manifest instanceof BatchManifest) {
            throw new RunNotAvailableException();
        }
        $names = [];
        foreach ($documents as $document) {
            $names[] = $document->provider->name();
        }
        usort($names, static fn (ProviderName $a, ProviderName $b): int => $a->value <=> $b->value);
        $held = [];
        try {
            foreach ($names as $name) {
                $left = $this->leases->acquire($name, $context->scope, $claim->holder, $this->settings->leaseSeconds);
                if ($left > 0) {
                    throw new LeaseHeldException($left);
                }
                $held[] = $name;
            }
            $this->heartbeat($claim, $run);
            $planContext = new SeedContext($context->scope, $context->actor, $context->channel, null, new PendingKeys(), $prune->prune, $context->canSeeCurrentValues);
            $plan        = $this->planner->plan($documents, $planContext, $prune);
            if ($run->batchesDone === 0 && !$plan->hash->equals($run->planHash)) {
                throw new StalePlanException();
            }
            // The re-plan may be long: show the claim is alive before the batches.
            $this->heartbeat($claim, $run);

            return $this->run($set, $documents, $context, $prune, $plan, $run, $held, $claim, true);
        } finally {
            foreach ($held as $name) {
                $this->release($name, $context, $claim->holder);
            }
        }
    }

    /**
     * A fenced heartbeat of a stored run.
     *
     * @throws ApplyInterruptedException when the claim was taken over (nothing written by this attempt)
     */
    private function heartbeat(RunClaim $claim, RunRecord $run): void
    {
        if (!$this->runs->heartbeat($claim->run, $claim->version)) {
            throw new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $run->batchesDone, $run->manifest?->writingBatches() ?? 0, $run->id->value, $this->settings->leaseSeconds);
        }
        $claim->advance();
    }

    /**
     * Applies the batches of a recorded run after `batches_done`, in the layout of its manifest, and ends it.
     *
     * @param list<PreparedDocument> $documents
     * @param list<ProviderName> $held
     *
     * @throws ApplyInterruptedException
     */
    private function run(DocumentSet $set, array $documents, SeedContext $context, PruneOptions $prune, SeedPlan $plan, RunRecord $record, array $held, RunClaim $claim, bool $stored): ApplyResult
    {
        $runId    = $record->id;
        $manifest = $record->manifest ?? BatchManifest::fromPlan($plan);
        $digests  = [];
        $writes   = [];
        foreach ($documents as $document) {
            $name           = $document->provider->name()->value;
            $digests[$name] = $manifest->digests($name);
            $writes[$name]  = $manifest->writes($name);
        }
        $providers = $record->providers;
        $total     = $manifest->writingBatches();
        $counts    = $record->counts + [ChangeOp::Create->value => 0, ChangeOp::Update->value => 0, ChangeOp::Delete->value => 0];

        $runContext = new SeedContext($context->scope, $context->actor, $context->channel, $runId, new PendingKeys(), $prune->prune, $context->canSeeCurrentValues);
        $done       = $record->batchesDone;
        $sequence   = 0;
        $step       = new BatchStep($runId, $total, $held, $claim);
        try {
            $seenByProvider = [];
            foreach ($documents as $document) {
                $name   = $document->provider->name();
                $seen   = new NaturalKeySet();
                $errors = new ErrorBag($this->settings->maxErrors);
                $number = 0;
                foreach ($this->planner->slices($document, $runContext, $seen, $errors) as $slice) {
                    ++$number;
                    if (!($writes[$name->value][$number - 1] ?? false) || ++$sequence <= $done) {
                        // Not writing, or committed by an earlier attempt of this run (resume / redelivery).
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
                        if (++$sequence <= $done) {
                            continue;
                        }
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
            $this->fail($e, $claim, $stored, $context->channel);

            throw $e;
        } catch (ConcurrencyConflict $e) {
            $interrupted = new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $done, $total, $runId->value, $e->retryAfterSeconds(), $e);
            $this->fail($interrupted, $claim, $stored, $context->channel);

            throw $interrupted;
        } catch (Throwable $e) {
            // Class and code only: driver messages quote row values (personal data, contract 13.1 / 14).
            $this->logger?->error('Seed run {run} failed in a provider: {exception_class} ({exception_code}).', ['run' => $runId->value, 'exception_class' => $e::class, 'exception_code' => (string) $e->getCode()]);
            $interrupted = new ApplyInterruptedException(ApplyInterruptedException::PROVIDER_FAILED, $done, $total, $runId->value, null, $e);
            $this->fail($interrupted, $claim, $stored, $context->channel);

            throw $interrupted;
        }
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $finished = $this->writer->write(fn (): bool => $this->runs->finish($runId, RunStatus::Applied, null, $claim->version));
        if ($finished !== true) {
            // Taken over after the last batch: the new claimer ends the run.
            $this->logger?->warning('Seed run {run} was taken over before it could be marked applied.', ['run' => $runId->value]);
        }
        $written = [ChangeOp::Create->value => $counts[ChangeOp::Create->value], ChangeOp::Update->value => $counts[ChangeOp::Update->value], ChangeOp::Delete->value => $counts[ChangeOp::Delete->value]];
        $this->dispatcher?->dispatch(new SeedRunAppliedEvent($runId, array_keys($providers), $context->scope, $written, $set->hash, $record->planHash, $context->actor, $context->channel));

        return new ApplyResult($plan, $runId, $done, $written);
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
            if (!$this->runs->markBatch($step->runId, $sequence, $counts, $step->claim->version)) {
                // The run's progress moved on without us (a replayed or skipped batch): roll the unit back.
                throw new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $before, $step->total, $step->runId->value);
            }

            return [$counts, $result, $changes];
        });
        $done = $sequence;
        $step->claim->advance();
        foreach ($changes as $change) {
            if ($change->op === ChangeOp::Create) {
                $context->pending->add($name, $change->key);
            }
        }
        $document->provider->afterBatch($context);
        foreach ($step->held as $lease) {
            if (!$this->leases->renew($lease, $context->scope, $step->claim->holder, $this->settings->leaseSeconds)) {
                throw new ApplyInterruptedException(ApplyInterruptedException::CONFLICT, $done, $step->total, $step->runId->value, 1);
            }
        }
        $this->dispatcher?->dispatch(new SeedBatchCommittedEvent($step->runId, $name, $sequence, $result->created, $result->updated, $result->deleted));
    }

    /**
     * Marks the run failed or partially applied (best effort, fenced by the claim: a taken-over attempt changes nothing)
     * and dispatches the failure event. A stored run that met a lock conflict before its first batch is handed back:
     * to `queued` on the Messenger channel (the transport's retry applies it, ZCE-L2), to `planned` on the web.
     */
    private function fail(ApplyInterruptedException $e, RunClaim $claim, bool $stored, Channel $channel): void
    {
        $runId = $claim->run;
        try {
            // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
            $this->writer->write(function () use ($claim, $e, $stored, $channel): bool {
                if ($stored && !$e->isPartial() && $e->kind === ApplyInterruptedException::CONFLICT) {
                    return $this->runs->transition($claim->run, $claim->version, [RunStatus::Running], $channel === Channel::Messenger ? RunStatus::Queued : RunStatus::Planned, $e->errorCode());
                }

                return $this->runs->finish($claim->run, $e->isPartial() ? RunStatus::PartiallyApplied : RunStatus::Failed, $e->errorCode(), $claim->version);
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
