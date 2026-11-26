<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use RuntimeException;

/**
 * A run store whose every write meets a lock conflict the write unit gave up on.
 */
final readonly class ConflictingRunStore implements RunStoreInterface
{
    public function create(RunRecord $run, ?string $document = null): void
    {
        throw $this->conflict();
    }

    public function markBatch(RunId $run, int $number, array $counts, ?int $version = null): bool
    {
        throw $this->conflict();
    }

    public function heartbeat(RunId $run, int $version): bool
    {
        throw $this->conflict();
    }

    public function finish(RunId $run, RunStatus $status, ?string $errorCode, ?int $version = null): bool
    {
        throw $this->conflict();
    }

    public function find(RunId $run): ?RunRecord
    {
        return null;
    }

    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null, ?ActorRef $actor = null): array
    {
        return [];
    }

    public function document(RunId $run): ?string
    {
        return null;
    }

    public function findPlanned(ActorRef $actor, SeedScope $scope, DocumentHash $document, PlanHash $plan, bool $prune, DateTimeImmutable $now): ?RunRecord
    {
        return null;
    }

    public function transition(RunId $run, ?int $version, array $from, RunStatus $to, ?string $errorCode = null, ?DateTimeImmutable $expiresAt = null, ?DateTimeImmutable $staleBefore = null): bool
    {
        throw $this->conflict();
    }

    public function expire(DateTimeImmutable $now, int $batchSize, ?DateTimeImmutable $staleBefore = null): int
    {
        throw $this->conflict();
    }

    public function purge(DateTimeImmutable $before, int $batchSize): int
    {
        throw $this->conflict();
    }

    public function countPurgeable(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function forgetActor(ActorRef $actor, int $batchSize): int
    {
        throw $this->conflict();
    }

    private function conflict(): ConcurrencyConflict
    {
        return new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new RuntimeException('deadlock'));
    }
}
