<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function krsort;

/**
 * The run history in memory for tests (per test instance), with the same compare-and-set and expiry rules as the DBAL
 * store. Instants come from the records and the callers (no clock of its own).
 */
final class InMemoryRunStore implements RunStoreInterface
{
    /** @var array<string, RunRecord> */
    private array $runs = [];

    /** @var array<string, string> */
    private array $documents = [];

    /**
     * {@inheritDoc}
     */
    public function create(RunRecord $run, ?string $document = null): void
    {
        $this->runs[$run->id->value] = $this->copy($run, ['hasDocument' => $document !== null]);
        if ($document !== null) {
            $this->documents[$run->id->value] = $document;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function document(RunId $run): ?string
    {
        return $this->documents[$run->value] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findPlanned(ActorRef $actor, SeedScope $scope, DocumentHash $document, PlanHash $plan, bool $prune, DateTimeImmutable $now): ?RunRecord
    {
        $runs = $this->runs;
        krsort($runs);
        foreach ($runs as $run) {
            if ($run->status === RunStatus::Planned && $run->isOwnedBy($actor) && $run->scope->equals($scope) && $run->documentHash?->value === $document->value
                && $run->planHash->equals($plan) && $run->prune === $prune && $run->expiresAt > $now && isset($this->documents[$run->id->value])) {
                return $run;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function transition(RunId $run, ?int $version, array $from, RunStatus $to, ?string $errorCode = null, ?DateTimeImmutable $expiresAt = null, ?DateTimeImmutable $staleBefore = null): bool
    {
        $record = $this->runs[$run->value] ?? null;
        if ($record === null || !in_array($record->status, $from, true) || ($version !== null && $record->version !== $version)) {
            return false;
        }
        if ($record->status === RunStatus::Running && $staleBefore instanceof DateTimeImmutable && ($record->updatedAt ?? $record->createdAt) >= $staleBefore) {
            return false;
        }
        $changes = ['status' => $to, 'version' => $record->version + 1];
        if ($errorCode !== null) {
            $changes['errorCode'] = $errorCode;
        }
        if ($expiresAt instanceof DateTimeImmutable) {
            $changes['expiresAt'] = $expiresAt;
        }
        if ($to->isFinal()) {
            unset($this->documents[$run->value]);
            $changes['hasDocument'] = false;
            $changes['finishedAt']  = new DateTimeImmutable();
        }
        $this->runs[$run->value] = $this->copy($record, $changes);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function expire(DateTimeImmutable $now, int $batchSize): int
    {
        $changed = 0;
        foreach ($this->runs as $id => $run) {
            if (!isset($this->documents[$id]) || !$run->expiresAt instanceof DateTimeImmutable || $run->expiresAt >= $now) {
                continue;
            }
            if (in_array($run->status, [RunStatus::Planned, RunStatus::Queued], true)) {
                unset($this->documents[$id]);
                $this->runs[$id] = $this->copy($run, ['status' => RunStatus::Expired, 'hasDocument' => false, 'finishedAt' => $now, 'version' => $run->version + 1]);
                ++$changed;
            } elseif ($run->status === RunStatus::PartiallyApplied) {
                unset($this->documents[$id]);
                $this->runs[$id] = $this->copy($run, ['hasDocument' => false, 'version' => $run->version + 1]);
                ++$changed;
            }
        }

        return $changed;
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
        $this->runs[$run->value] = $this->copy($record, ['batchesDone' => $number, 'counts' => $counts, 'version' => $record->version + 1]);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function finish(RunId $run, RunStatus $status, ?string $errorCode): void
    {
        $record = $this->runs[$run->value] ?? null;
        if ($record !== null) {
            $changes = ['status' => $status, 'errorCode' => $errorCode, 'finishedAt' => new DateTimeImmutable(), 'version' => $record->version + 1];
            if ($status->isFinal()) {
                unset($this->documents[$run->value]);
                $changes['hasDocument'] = false;
            }
            $this->runs[$run->value] = $this->copy($record, $changes);
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
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null, ?ActorRef $actor = null): array
    {
        $runs = $this->runs;
        krsort($runs);
        $out = [];
        foreach ($runs as $id => $run) {
            if ((!$scope instanceof SeedScope || $run->scope->equals($scope)) && (!$status instanceof RunStatus || $run->status === $status)
                && (!$before instanceof RunId || $id < $before->value) && (!$actor instanceof ActorRef || $run->isOwnedBy($actor))) {
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
                unset($this->runs[$id], $this->documents[$id]);
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
            if ($run->isOwnedBy($actor)) {
                $this->runs[$id] = $this->copy($run, ['actor' => null]);
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * Every run (oldest first), for assertions.
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
     * Moves the last progress of a run (tests of the stale-claim takeover).
     */
    public function touch(RunId $run, DateTimeImmutable $updatedAt): void
    {
        $record = $this->runs[$run->value] ?? null;
        if ($record !== null) {
            $this->runs[$run->value] = $this->copy($record, ['updatedAt' => $updatedAt]);
        }
    }

    /**
     * A copy with some fields changed.
     *
     * @param array{status?: RunStatus, errorCode?: ?string, batchesDone?: int, counts?: array<string, int>, actor?: ?ActorRef, finishedAt?: ?DateTimeImmutable, version?: int, expiresAt?: ?DateTimeImmutable, hasDocument?: bool, updatedAt?: ?DateTimeImmutable} $changes
     */
    private function copy(RunRecord $run, array $changes): RunRecord
    {
        /* @var array{status?: RunStatus, errorCode?: ?string, batchesDone?: int, counts?: array<string, int>, actor?: ?ActorRef, finishedAt?: ?DateTimeImmutable, version?: int, expiresAt?: ?DateTimeImmutable, hasDocument?: bool, updatedAt?: ?DateTimeImmutable} $changes */
        return new RunRecord(
            $run->id,
            $run->scope,
            array_key_exists('actor', $changes) ? $changes['actor'] : $run->actor,
            $run->channel,
            $changes['status'] ?? $run->status,
            $run->providers,
            $run->prune,
            $run->documentHash,
            $run->planHash,
            $run->batchDigests,
            $changes['batchesDone'] ?? $run->batchesDone,
            $changes['counts'] ?? $run->counts,
            array_key_exists('errorCode', $changes) ? $changes['errorCode'] : $run->errorCode,
            $run->createdAt,
            array_key_exists('finishedAt', $changes) ? $changes['finishedAt'] : $run->finishedAt,
            $run->manifest,
            $changes['version'] ?? $run->version,
            array_key_exists('expiresAt', $changes) ? $changes['expiresAt'] : $run->expiresAt,
            $changes['hasDocument'] ?? $run->hasDocument,
            array_key_exists('updatedAt', $changes) ? $changes['updatedAt'] : ($run->updatedAt ?? $run->createdAt),
        );
    }
}
