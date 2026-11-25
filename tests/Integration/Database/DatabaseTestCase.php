<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalLeaseStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function sprintf;
use function str_repeat;

/**
 * A fresh database per test (SQLite, or the CI matrix through `NOWO_TEST_DATABASE_URL`) with the bundle's tables
 * (XML mapping, prefix `nowo_`) and the DBAL stores built by hand on a MockClock.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    protected EntityManager $entityManager;

    protected MockClock $clock;

    protected DbalRunStore $runs;

    protected DbalLeaseStore $leases;

    protected function setUp(): void
    {
        [$this->connection, $this->entityManager] = TestDatabase::fresh();
        $this->clock                              = new MockClock('2026-11-24 10:00:00', 'Europe/Madrid');
        $this->runs                               = new DbalRunStore($this->connection, new TableNames(), $this->clock);
        $this->leases                             = new DbalLeaseStore($this->connection, new TableNames(), $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            $this->connection->close();
        }
    }

    /**
     * A run id from a number (UUID-shaped, ordered).
     */
    protected static function runId(int $number): RunId
    {
        return new RunId(sprintf('01936f2a-0000-7000-8000-%012d', $number));
    }

    /**
     * A run record.
     */
    protected function record(int $number, RunStatus $status = RunStatus::Running, ?string $actor = 'alice', ?SeedScope $scope = null, ?DateTimeImmutable $createdAt = null): RunRecord
    {
        return new RunRecord(
            self::runId($number),
            $scope ?? SeedScope::platform(),
            $actor !== null ? new ActorRef($actor) : null,
            Channel::Console,
            $status,
            ['host.tags' => 1, 'host.articles' => 2],
            $number % 2 === 0,
            new DocumentHash('sha256:' . str_repeat('a', 64)),
            new PlanHash('sk1:' . str_repeat('b', 64)),
            ['d1', 'd2'],
            0,
            ['create' => 0, 'update' => 0, 'delete' => 0],
            null,
            $createdAt ?? $this->clock->now(),
        );
    }
}
