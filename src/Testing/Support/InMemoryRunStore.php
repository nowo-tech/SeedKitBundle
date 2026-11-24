<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;

use function array_slice;
use function array_values;
use function count;
use function krsort;

/**
 * The run history in memory for tests (per test instance).
 */
final class InMemoryRunStore implements RunStoreInterface
{
    /** @var array<string, RunRecord> */
    private array $runs = [];

    /**
     * {@inheritDoc}
     */
    public function create(RunRecord $run): void
    {
        $this->runs[$run->id->value] = $run;
    }

    /**
     * {@inheritDoc}
     */
    public function markBatch(RunId $run, int $number, array $counts): bool
    {
        $record = $this->runs[$run->value] ?? null;
        if ($record === null || $record->batchesDone !== $number - 1) {
            return false;
        }
        $this->runs[$run->value] = $this->with($record, $record->status, $record->errorCode, $number, $counts, $record->actor, $record->finishedAt);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function finish(RunId $run, RunStatus $status, ?string $errorCode): void
    {
        $record = $this->runs[$run->value] ?? null;
        if ($record !== null) {
            $this->runs[$run->value] = $this->with($record, $status, $errorCode, $record->batchesDone, $record->counts, $record->actor, new DateTimeImmutable());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function find(RunId $run): ?RunRecord
    {
        return $this->runs[$run->value] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null): array
    {
        $runs = $this->runs;
        krsort($runs);
        $out = [];
        foreach ($runs as $id => $run) {
            if (($scope === null || $run->scope->equals($scope)) && ($status === null || $run->status === $status) && ($before === null || $id < $before->value)) {
                $out[] = $run;
            }
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * {@inheritDoc}
     */
    public function purge(DateTimeImmutable $before, int $batchSize): int
    {
        $deleted = 0;
        foreach ($this->runs as $id => $run) {
            if ($run->createdAt < $before && $run->status->isFinal()) {
                unset($this->runs[$id]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function countPurgeable(DateTimeImmutable $before): int
    {
        $count = 0;
        foreach ($this->runs as $run) {
            $count += $run->createdAt < $before && $run->status->isFinal() ? 1 : 0;
        }

        return $count;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetActor(ActorRef $actor, int $batchSize): int
    {
        $changed = 0;
        foreach ($this->runs as $id => $run) {
            if ($run->actor !== null && $run->actor->value === $actor->value) {
                $this->runs[$id] = $this->with($run, $run->status, $run->errorCode, $run->batchesDone, $run->counts, null, $run->finishedAt);
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Every run (newest last), for assertions.
     *
     * @return list<RunRecord>
     */
    public function all(): array
    {
        return array_values($this->runs);
    }

    /**
     * The number of runs.
     */
    public function count(): int
    {
        return count($this->runs);
    }

    /**
     * A copy with other mutable fields.
     *
     * @param array<string, int> $counts
     */
    private function with(RunRecord $run, RunStatus $status, ?string $errorCode, int $batches, array $counts, ?ActorRef $actor, ?DateTimeImmutable $finishedAt): RunRecord
    {
        return new RunRecord($run->id, $run->scope, $actor, $run->channel, $status, $run->providers, $run->prune, $run->documentHash, $run->planHash, $run->batchDigests, $batches, $counts, $errorCode, $run->createdAt, $finishedAt);
    }
}
