<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;

/**
 * The run history (`<prefix>seed_run`, contract section 11). Called inside the write units of the applier.
 */
interface RunStoreInterface
{
    /**
     * Inserts a new run row.
     */
    public function create(RunRecord $run): void;

    /**
     * Records that batch `$number` committed, in the batch transaction: succeeds only when the run's progress is
     * `$number - 1` (a replayed batch is never counted twice).
     *
     * @param array<string, int> $counts ChangeOp value → rows written so far
     *
     * @return bool false when the batch was already recorded
     */
    public function markBatch(RunId $run, int $number, array $counts): bool;

    /**
     * Ends a run with a status and an optional error code.
     */
    public function finish(RunId $run, RunStatus $status, ?string $errorCode): void;

    /**
     * One run, or null.
     */
    public function find(RunId $run): ?RunRecord;

    /**
     * The newest runs (keyset by creation, id), optionally of one scope or status.
     *
     * @return list<RunRecord>
     */
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null): array;

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
