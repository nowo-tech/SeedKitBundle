<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;

/**
 * The run history (`<prefix>seed_run`, contract section 11). Called inside the write units of the applier. 0.2 adds the
 * stored document of UI runs, the compare-and-set run claim (`version`, contract section 10.3) and the expiry of
 * documents after `runs.document_ttl`.
 */
interface RunStoreInterface
{
    /**
     * Inserts a new run row, with the stored document of a UI run (`$run->expiresAt` is its expiry).
     */
    public function create(RunRecord $run, ?string $document = null): void;

    /**
     * The stored document of a run, or null (console run, ended, discarded or expired).
     */
    public function document(RunId $run): ?string;

    /**
     * A `planned` run of the same actor, scope, raw document, prune flag and plan hash that has not expired (a second
     * upload of the same file reuses it instead of storing the document twice).
     */
    public function findPlanned(ActorRef $actor, SeedScope $scope, DocumentHash $document, PlanHash $plan, bool $prune, DateTimeImmutable $now): ?RunRecord;

    /**
     * Compare-and-set status change (the run claim): succeeds only when the run is in one of `$from` and, with a
     * `$version`, still at that version. With `$staleBefore` (a claim) a `running` run is taken over only when its
     * last progress is older than that instant (a crashed worker); without it the claimer moves its own run on. Bumps
     * the version. A final `$to` clears the document and sets `finished_at`.
     *
     * @param list<RunStatus> $from
     */
    public function transition(RunId $run, ?int $version, array $from, RunStatus $to, ?string $errorCode = null, ?DateTimeImmutable $expiresAt = null, ?DateTimeImmutable $staleBefore = null): bool;

    /**
     * Ends the documents older than their expiry, in batches: `planned` / `queued` runs become `expired`; a
     * `partially_applied` run keeps its status and loses its document; with `$staleBefore`, a `running` run without
     * progress since that instant (a killed request or worker) becomes `partially_applied` (batches committed) or
     * `expired` and loses its document too. Returns the rows changed.
     */
    public function expire(DateTimeImmutable $now, int $batchSize, ?DateTimeImmutable $staleBefore = null): int;

    /**
     * Heartbeat of a claimed run (fenced): moves `updated_at` on and bumps the version, only while the run is
     * `running` at `$version`. False when another claim took the run over.
     */
    public function heartbeat(RunId $run, int $version): bool;

    /**
     * Records that batch `$number` committed, in the batch transaction: succeeds only when the run's progress is
     * `$number - 1` (a replayed batch is never counted twice).
     *
     * @param array<string, int> $counts changeOp value → rows written so far
     *
     * With `$version` (the claim's fencing version) it also requires the run to be `running` at that version, and bumps
     * it: a worker whose run was taken over can no longer record a batch
     *
     * @return bool false when the batch was already recorded or the claim was lost
     */
    public function markBatch(RunId $run, int $number, array $counts, ?int $version = null): bool;

    /**
     * Ends a run with a status and an optional error code. A final status clears the stored document; a
     * `partially_applied` run keeps it until it expires (it may be resumed). With `$version` (fenced) only a `running`
     * run at that version is ended.
     *
     * @return bool false when the fenced run was taken over (nothing changed)
     */
    public function finish(RunId $run, RunStatus $status, ?string $errorCode, ?int $version = null): bool;

    /**
     * One run, or null.
     */
    public function find(RunId $run): ?RunRecord;

    /**
     * The newest runs (keyset by creation, id), optionally of one scope, status or actor.
     *
     * @return list<RunRecord>
     */
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null, ?ActorRef $actor = null): array;

    /**
     * Deletes finished runs created before the instant, in batches; returns the rows deleted.
     */
    public function purge(DateTimeImmutable $before, int $batchSize): int;

    /**
     * Counts the finished runs created before the instant (purge dry run).
     */
    public function countPurgeable(DateTimeImmutable $before): int;

    /**
     * Nulls the actor of every run of `$actor`, in batches; returns the rows changed.
     */
    public function forgetActor(ActorRef $actor, int $batchSize): int;
}
