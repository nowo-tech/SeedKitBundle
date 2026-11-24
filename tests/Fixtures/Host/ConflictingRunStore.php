<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
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
    public function create(RunRecord $run): void
    {
        throw self::conflict();
    }

    public function markBatch(RunId $run, int $number, array $counts): bool
    {
        throw self::conflict();
    }

    public function finish(RunId $run, RunStatus $status, ?string $errorCode): void
    {
        throw self::conflict();
    }

    public function find(RunId $run): ?RunRecord
    {
        return null;
    }

    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null): array
    {
        return [];
    }

    public function purge(DateTimeImmutable $before, int $batchSize): int
    {
        throw self::conflict();
    }

    public function countPurgeable(DateTimeImmutable $before): int
    {
        return 0;
    }

    public function forgetActor(ActorRef $actor, int $batchSize): int
    {
        throw self::conflict();
    }

    private static function conflict(): ConcurrencyConflict
    {
        return new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 1, new RuntimeException('deadlock'));
    }
}
