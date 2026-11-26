<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use DateInterval;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\PlanTooLargeException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
use Nowo\SeedKitBundle\Domain\Exception\SeedKitException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\SeedKitBundle\Domain\Port\RunDocumentCodecInterface;
use Nowo\SeedKitBundle\Domain\Port\RunQueueInterface;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\SeedKitBundle\Domain\Run\BatchManifest;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_keys;
use function in_array;

/**
 * The stored runs of the UI and of the asynchronous apply (contract sections 10.2, 10.3, 10.5, 11; 0.2):
 *
 * - upload: dry run (scope resolved, `SEED_APPLY` / `SEED_PRUNE` checked, document validated and planned), then a
 *   `planned` run row holding the parsed document (gzip) for `runs.document_ttl`; the same file uploaded again by the
 *   same actor reuses the run;
 * - view: the run as it is now (its actor sees the current diff while the document is kept; another viewer needs
 *   `SEED_APPLY` on every provider of the run and sees metadata only);
 * - apply: same actor, reviewed plan hash, access re-checked, run claimed by compare-and-set (`planned` /
 *   `partially_applied` → `running`, one click wins), then the applier; above `messenger.async_threshold_changes` it is
 *   queued instead (`queued`, message with the run id and plan hash only), above `ui.max_sync_changes` without
 *   Messenger it is refused (`SEED_009`);
 * - Messenger: the handler re-resolves the scope and re-checks access for the run's actor on the Messenger channel
 *   (the `system` actor is refused), claims the run (a crashed worker's `running` run after the lease), and resumes
 *   after `batches_done`;
 * - discard, progress, history, expiry.
 *
 * Unknown runs, runs of another scope or (for writes) another actor answer `SEED_008` (not found). Every apply, prune
 * and discard is audited with its outcome. Stateless.
 */
final readonly class SeedRuns
{
    /** Rows per opportunistic expiry batch on upload. */
    public const EXPIRE_BATCH = 100;

    /**
     * Builds the service.
     */
    public function __construct(
        private SeedOperations $operations,
        private Applier $applier,
        private RunStoreInterface $runs,
        private RunDocumentCodecInterface $codec,
        private WriteRunnerInterface $writer,
        private SeedAuditSinkInterface $audit,
        private IdGeneratorInterface $ids,
        private SeedKitSettings $settings,
        private ClockInterface $clock,
        private ?RunQueueInterface $queue = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether applies above the threshold are queued (Messenger bridge enabled).
     */
    public function isAsync(): bool
    {
        return $this->queue instanceof RunQueueInterface;
    }

    /**
     * Dry-runs an uploaded document set and stores it as a `planned` run (or returns the same planned run).
     *
     * @throws SeedKitException
     */
    public function upload(DocumentSet $set, SeedScope $requested, ActorRef $actor, bool $prune): RunRecord
    {
        $now = DateTimeImmutable::createFromInterface($this->clock->now());
        $this->expire($now, self::EXPIRE_BATCH);
        $mass = false;
        try {
            $plan = $this->operations->diff($set, $requested, $actor, Channel::Http, new PruneOptions($prune));
        } catch (PruneRefusedException $e) {
            if ($e->guard !== PruneRefusedException::MASS) {
                throw $e;
            }
            // Shown with a warning and applied only after the typed confirmation of the delete count (contract 10.4).
            $mass = true;
            $plan = $this->operations->diff($set, $requested, $actor, Channel::Http, new PruneOptions(true, true));
        }
        $existing = $this->runs->findPlanned($actor, $plan->scope, $set->hash, $plan->hash, $prune, $now);
        if ($existing instanceof RunRecord) {
            return $existing;
        }
        $manifest  = BatchManifest::fromPlan($plan);
        $providers = [];
        foreach ($plan->documents as $document) {
            $providers[$document->provider->value] = $document->version;
        }
        $counts = [ChangeOp::Create->value => 0, ChangeOp::Update->value => 0, ChangeOp::Delete->value => 0];
        foreach (ChangeOp::cases() as $op) {
            $counts[RunRecord::PLANNED . $op->value] = $plan->count($op);
        }
        $counts[RunRecord::MASS_PRUNE] = $mass ? 1 : 0;
        $record                        = new RunRecord(
            $this->ids->runId(),
            $plan->scope,
            $actor,
            Channel::Http,
            RunStatus::Planned,
            $providers,
            $prune,
            $set->hash,
            $plan->hash,
            $manifest->flat(),
            0,
            $counts,
            null,
            $now,
            null,
            $manifest,
            1,
            $this->expiry($now),
            true,
            $now,
        );
        $document = $this->codec->encode($set);
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $this->writer->write(function () use ($record, $document): null {
            $this->runs->create($record, $document);

            return null;
        });

        return $record;
    }

    /**
     * The run page: the run, and for its actor while the document is kept, the current diff.
     *
     * @throws SeedKitException
     */
    public function view(RunId $id, SeedScope $requested, ActorRef $viewer): RunView
    {
        $scope = $this->operations->resolveScope($requested, $viewer, Channel::Http);
        $run   = $this->visible($id, $scope, $viewer);
        $owner = $run->isOwnedBy($viewer);
        if (!$owner || !$run->hasDocument || !in_array($run->status, [RunStatus::Planned, RunStatus::PartiallyApplied], true)) {
            return new RunView($run, null, false, $owner);
        }
        try {
            $plan = $this->operations->diff($this->load($run), $scope, $viewer, Channel::Http, new PruneOptions($run->prune, true, true), false);
        } catch (SeedKitException $e) {
            return new RunView($run, null, false, true, $e->errorCode());
        }

        return new RunView($run, $plan, $run->batchesDone === 0 && !$plan->hash->equals($run->planHash), true);
    }

    /**
     * The run row for the progress poll (same visibility as the page).
     *
     * @throws SeedKitException
     */
    public function progress(RunId $id, SeedScope $requested, ActorRef $viewer): RunRecord
    {
        return $this->visible($id, $this->operations->resolveScope($requested, $viewer, Channel::Http), $viewer);
    }

    /**
     * Applies (or queues) a planned run from the UI: same actor, the reviewed plan hash, the typed confirmation of a
     * mass prune.
     *
     * @throws SeedKitException
     */
    public function apply(RunId $id, PlanHash $expected, SeedScope $requested, ActorRef $actor, bool $massPruneConfirmed = false): ApplyOutcome
    {
        $scope = $this->operations->resolveScope($requested, $actor, Channel::Http);
        $run   = $this->owned($id, $scope, $actor);
        if (in_array($run->status, [RunStatus::Applied, RunStatus::Running, RunStatus::Queued], true)) {
            return new ApplyOutcome(ApplyOutcome::BUSY, $run);
        }
        if (!in_array($run->status, [RunStatus::Planned, RunStatus::PartiallyApplied], true)) {
            throw new RunNotAvailableException();
        }
        if (!$expected->equals($run->planHash)) {
            throw new StalePlanException();
        }
        if ($run->isMassPrune() && !$massPruneConfirmed) {
            throw new PruneRefusedException(PruneRefusedException::MASS, $run->planned(ChangeOp::Delete->value), 0);
        }
        $set   = $this->load($run);
        $prune = new PruneOptions($run->prune, $run->isMassPrune());
        if ($this->queue instanceof RunQueueInterface && $run->changes() > $this->settings->asyncThreshold) {
            return $this->enqueue($run, $set, $scope, $actor, $prune, $this->queue);
        }
        if ($run->changes() > $this->settings->maxSyncChanges) {
            throw new PlanTooLargeException($run->changes(), $this->settings->maxSyncChanges);
        }

        return $this->execute($run, $set, $scope, $actor, Channel::Http, $prune, [RunStatus::Planned, RunStatus::PartiallyApplied]);
    }

    /**
     * Applies a queued run (Messenger handler): the run's actor on the Messenger channel, access re-checked; an
     * unknown, ended or already claimed run is a no-op (redelivery), and so is a message whose plan hash is not the
     * run's.
     *
     * @throws SeedKitException
     */
    public function applyQueued(RunId $id, PlanHash $planHash): ?ApplyOutcome
    {
        $run = $this->runs->find($id);
        if (!$run instanceof RunRecord || !$planHash->equals($run->planHash)
            || !in_array($run->status, [RunStatus::Queued, RunStatus::PartiallyApplied, RunStatus::Running], true)) {
            $this->logger?->info('Seed run message {run} skipped: the run is not waiting for the asynchronous apply.', ['run' => $id->value]);

            return null;
        }
        $from = [RunStatus::Queued, RunStatus::PartiallyApplied, RunStatus::Running];
        try {
            $actor = $run->actor ?? throw new RunNotAvailableException();
            $scope = $this->operations->resolveScope($run->scope, $actor, Channel::Messenger);
            $set   = $this->load($run);
        } catch (SeedKitException $e) {
            // Refused for good (forgotten or `system` actor, scope gone, document expired): never retried.
            $this->writer->write(function () use ($run, $e): null { // @igor-ignore - called at once inside one write unit; nothing kept
                $this->runs->transition($run->id, null, [RunStatus::Queued], RunStatus::Failed, $e->errorCode());

                return null;
            });
            if ($run->actor instanceof ActorRef) {
                $this->auditFailure($run, $run->actor, $run->scope, Channel::Messenger, $e);
            }

            throw $e;
        }

        return $this->execute($run, $set, $scope, $actor, Channel::Messenger, new PruneOptions($run->prune, $run->isMassPrune()), $from);
    }

    /**
     * Discards a run before it is applied (its document is deleted).
     *
     * @throws SeedKitException
     */
    public function discard(RunId $id, SeedScope $requested, ActorRef $actor): RunRecord
    {
        $scope = $this->operations->resolveScope($requested, $actor, Channel::Http);
        $run   = $this->owned($id, $scope, $actor);
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $done = $this->writer->write(fn (): bool => $this->runs->transition($run->id, $run->version, [RunStatus::Planned, RunStatus::PartiallyApplied], RunStatus::Discarded));
        if ($done !== true) {
            throw new RunNotAvailableException();
        }
        $this->audit->record(new SeedAuditRecord(SeedAuditRecord::DISCARD, $actor, $scope, array_keys($run->providers), [], Channel::Http, SeedAuditRecord::OUTCOME_SUCCESS, $run->documentHash?->value, $run->planHash->value, $run->id->value));

        return $this->runs->find($run->id) ?? $run;
    }

    /**
     * The newest runs of a scope: every run when the viewer may apply every provider of the scope, else the viewer's
     * own runs (keyset pager).
     *
     * @throws SeedKitException
     *
     * @return list<RunRecord>
     */
    public function history(SeedScope $requested, ActorRef $viewer, int $limit, ?RunId $before = null): array
    {
        $scope = $this->operations->resolveScope($requested, $viewer, Channel::Http);
        $all   = false;
        foreach ($this->operations->providers($scope) as $provider) {
            $all = $this->operations->isGranted(SeedAction::Apply, $provider->name(), $scope, Channel::Http, $viewer);
            if (!$all) {
                break;
            }
        }

        return $this->runs->list($scope, null, $limit, $before, $all ? null : $viewer);
    }

    /**
     * Ends the documents past their expiry (best effort; the purge command and the Scheduler bridge call it too).
     */
    public function expire(?DateTimeImmutable $now = null, int $batchSize = 500): int
    {
        $now ??= DateTimeImmutable::createFromInterface($this->clock->now());
        try {
            // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
            $changed = $this->writer->write(fn (): int => $this->runs->expire($now, $batchSize));
        } catch (Throwable $e) {
            $this->logger?->warning('Seed run documents could not be expired: {exception_class}.', ['exception_class' => $e::class]);

            return 0;
        }

        return $changed;
    }

    /**
     * Queues a run for the asynchronous apply (access checked now and again by the handler).
     *
     * @throws SeedKitException
     */
    private function enqueue(RunRecord $run, DocumentSet $set, SeedScope $scope, ActorRef $actor, PruneOptions $prune, RunQueueInterface $queue): ApplyOutcome
    {
        try {
            $this->operations->prepare($set, $scope, $actor, Channel::Http, $prune);
        } catch (Throwable $e) {
            $this->auditFailure($run, $actor, $scope, Channel::Http, $e);

            throw $e;
        }
        $expiry = $this->expiry(DateTimeImmutable::createFromInterface($this->clock->now()));
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $claimed = $this->writer->write(fn (): bool => $this->runs->transition($run->id, $run->version, [RunStatus::Planned, RunStatus::PartiallyApplied], RunStatus::Queued, null, $expiry));
        if ($claimed !== true) {
            return new ApplyOutcome(ApplyOutcome::BUSY, $this->runs->find($run->id) ?? $run);
        }
        try {
            $queue->queue($run->id, $run->planHash);
        } catch (Throwable $e) {
            // Not handed over: back to where it was, so the user can try again.
            $this->writer->write(function () use ($run): null { // @igor-ignore - called at once inside one write unit; nothing kept
                $this->runs->transition($run->id, null, [RunStatus::Queued], $run->status);

                return null;
            });
            $this->logger?->error('Seed run {run} could not be queued: {exception_class}.', ['run' => $run->id->value, 'exception_class' => $e::class]);

            throw $e;
        }
        $this->audit->record(new SeedAuditRecord($run->prune ? SeedAuditRecord::PRUNE : SeedAuditRecord::APPLY, $actor, $scope, array_keys($run->providers), [], Channel::Http, SeedAuditRecord::OUTCOME_QUEUED, $run->documentHash?->value, $run->planHash->value, $run->id->value));

        return new ApplyOutcome(ApplyOutcome::QUEUED, $this->runs->find($run->id) ?? $run);
    }

    /**
     * Access re-check, claim, apply, release on failures that wrote nothing; audited with every outcome.
     *
     * @param list<RunStatus> $from statuses the claim may start from
     *
     * @throws SeedKitException
     */
    private function execute(RunRecord $run, DocumentSet $set, SeedScope $scope, ActorRef $actor, Channel $channel, PruneOptions $prune, array $from): ApplyOutcome
    {
        try {
            [$documents, $context] = $this->operations->prepare($set, $scope, $actor, $channel, $prune);
        } catch (Throwable $e) {
            if ($channel === Channel::Messenger && $e instanceof SeedKitException) {
                $this->writer->write(function () use ($run, $e): null { // @igor-ignore - called at once inside one write unit; nothing kept
                    $this->runs->transition($run->id, null, [RunStatus::Queued], RunStatus::Failed, $e->errorCode());

                    return null;
                });
            }
            $this->auditFailure($run, $actor, $scope, $channel, $e);

            throw $e;
        }
        $stale = DateTimeImmutable::createFromInterface($this->clock->now())->sub(new DateInterval('PT' . $this->settings->leaseSeconds . 'S'));
        // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
        $claimed = $this->writer->write(fn (): bool => $this->runs->transition($run->id, $run->version, $from, RunStatus::Running, null, null, $stale));
        $current = $claimed === true ? $this->runs->find($run->id) : null;
        if (!$current instanceof RunRecord) {
            return new ApplyOutcome(ApplyOutcome::BUSY, $this->runs->find($run->id) ?? $run);
        }
        // What the run goes back to when nothing was written (a crashed worker's run counts as partially applied).
        $back = $run->status === RunStatus::Running ? RunStatus::PartiallyApplied : $run->status;
        try {
            $result = $this->applier->applyStored($current, $set, $documents, $context, $prune);
        } catch (ApplyInterruptedException $e) {
            // The applier recorded the outcome on the run row.
            $this->auditFailure($run, $actor, $scope, $channel, $e);

            throw $e;
        } catch (StalePlanException $e) {
            $this->release($run->id, RunStatus::Failed, $e->errorCode());
            $this->auditFailure($run, $actor, $scope, $channel, $e);

            throw $e;
        } catch (Throwable $e) {
            // Lease held, prune refused, a provider failing in the re-plan: nothing written.
            $this->release($run->id, $e instanceof LeaseHeldException && $channel === Channel::Messenger ? RunStatus::Queued : $back, $e instanceof SeedKitException ? $e->errorCode() : null);
            $this->auditFailure($run, $actor, $scope, $channel, $e);

            throw $e;
        }
        $this->audit->record(new SeedAuditRecord(
            $run->prune ? SeedAuditRecord::PRUNE : SeedAuditRecord::APPLY,
            $actor,
            $scope,
            array_keys($run->providers),
            $result->written,
            $channel,
            SeedAuditRecord::OUTCOME_SUCCESS,
            $run->documentHash?->value,
            $run->planHash->value,
            $run->id->value,
        ));

        return new ApplyOutcome(ApplyOutcome::APPLIED, $this->runs->find($run->id) ?? $current, $result);
    }

    /**
     * Moves a claimed run out of `running` (best effort: a stuck claim is taken over after the lease anyway).
     */
    private function release(RunId $id, RunStatus $to, ?string $errorCode): void
    {
        try {
            // @igor-ignore - the write runner calls the closure inside one write unit and keeps nothing.
            $this->writer->write(fn (): bool => $this->runs->transition($id, null, [RunStatus::Running], $to, $errorCode));
        } catch (Throwable $e) {
            $this->logger?->warning('Seed run {run} could not be released: {exception_class}.', ['run' => $id->value, 'exception_class' => $e::class]);
        }
    }

    /**
     * Audits a failed apply (outcome = the error code, never the message).
     */
    private function auditFailure(RunRecord $run, ActorRef $actor, SeedScope $scope, Channel $channel, Throwable $e): void
    {
        $this->audit->record(new SeedAuditRecord(
            $run->prune ? SeedAuditRecord::PRUNE : SeedAuditRecord::APPLY,
            $actor,
            $scope,
            array_keys($run->providers),
            [],
            $channel,
            $e instanceof SeedKitException ? $e->errorCode() : SeedAuditRecord::OUTCOME_ERROR,
            $run->documentHash?->value,
            $run->planHash->value,
            $run->id->value,
        ));
    }

    /**
     * A run of the scope the viewer may see: their own, or any when they may apply every provider of it.
     *
     * @throws RunNotAvailableException
     */
    private function visible(RunId $id, SeedScope $scope, ActorRef $viewer): RunRecord
    {
        $run = $this->runs->find($id);
        if (!$run instanceof RunRecord || !$run->scope->equals($scope)) {
            throw new RunNotAvailableException();
        }
        if ($run->isOwnedBy($viewer)) {
            return $run;
        }
        foreach (array_keys($run->providers) as $name) {
            if (!$this->operations->isGranted(SeedAction::Apply, new ProviderName((string) $name), $scope, Channel::Http, $viewer)) {
                throw new RunNotAvailableException();
            }
        }

        return $run;
    }

    /**
     * A run of the scope that belongs to the actor (writes are bound to the run's actor).
     *
     * @throws RunNotAvailableException
     */
    private function owned(RunId $id, SeedScope $scope, ActorRef $actor): RunRecord
    {
        $run = $this->runs->find($id);
        if (!$run instanceof RunRecord || !$run->scope->equals($scope) || !$run->isOwnedBy($actor)) {
            throw new RunNotAvailableException();
        }

        return $run;
    }

    /**
     * The stored document of a run.
     *
     * @throws RunNotAvailableException when it is gone
     */
    private function load(RunRecord $run): DocumentSet
    {
        $stored = $this->runs->document($run->id);
        if ($stored === null) {
            throw new RunNotAvailableException();
        }

        return $this->codec->decode($stored);
    }

    /**
     * The document expiry from an instant.
     */
    private function expiry(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->add(new DateInterval('PT' . $this->settings->documentTtl . 'S'));
    }
}
