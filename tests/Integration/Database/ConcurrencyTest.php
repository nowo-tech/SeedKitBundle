<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalLeaseStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use Nowo\TransactionContract\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/**
 * REQ-RUNTIME-003 with two connections on a real database (MySQL, MariaDB, PostgreSQL; skipped on SQLite, whose write
 * transaction locks the whole file): the lease keeps two applies of one provider and scope apart, an edit committed
 * by another connection during the run stops it at the changed batch, and a row lock held by another connection makes
 * the batch wait, replay and give up with CONFLICT_001 (nothing committed) or CONFLICT_002 (after a committed batch).
 * The connection stays usable and a re-apply converges.
 */
final class ConcurrencyTest extends ApplyTestCase
{
    private Connection $other;

    protected function setUp(): void
    {
        if (!TestDatabase::isServer()) {
            self::markTestSkipped('Two-connection race tests need MySQL, MariaDB or PostgreSQL (CI "Databases" job).');
        }
        $this->boot(['apply' => ['batch_size' => 1]]);
        TestDatabase::shortLockWait($this->connection());
        $this->other = TestDatabase::connection();
        $this->insertTag('a', 'A0');
        $this->insertTag('b', 'B0');
    }

    protected function tearDown(): void
    {
        if (isset($this->other)) {
            if ($this->other->isTransactionActive()) {
                $this->other->rollBack();
            }
            $this->other->close();
        }
        parent::tearDown();
    }

    #[Test]
    public function twoAppliesOfOneProviderAndScopeNeverRunTogether(): void
    {
        $leases                     = new DbalLeaseStore($this->other, new TableNames(), new MockClock('2026-11-24 10:00:00', 'UTC'));
        $tags                       = new ProviderName('host.tags');
        $seen                       = [];
        $this->hooks()->beforeApply = static function (string $provider, AppliedBatch $batch) use ($leases, $tags, &$seen): void {
            $seen[] = $leases->acquire($tags, SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000999'), 300);
        };
        $this->apply($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])));
        self::assertSame([300, 300], $seen, 'while the run applies, the other one is told to wait');

        $this->hooks()->beforeApply = null;
        self::assertSame(0, $leases->acquire($tags, SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000999'), 300), 'released in finally');
        try {
            $this->apply($this->read(self::tags(['a' => 'A2'])));
            self::fail('two runs held the lease');
        } catch (LeaseHeldException $e) {
            self::assertSame('CONFLICT_001', $e->errorCode());
            self::assertSame(300, $e->retryAfterSeconds);
        }
        self::assertSame(['a' => 'A1', 'b' => 'B1'], $this->labels());
    }

    #[Test]
    public function anEditCommittedDuringTheRunStopsItAtTheChangedBatch(): void
    {
        $other                      = $this->other;
        $this->hooks()->beforeApply = static function (string $provider, AppliedBatch $batch) use ($other): void {
            if ($batch->number === 1) {
                $other->executeStatement("UPDATE host_tag SET label = 'B-edited' WHERE scope_key = 'platform' AND slug = 'b'");
            }
        };
        $set = $this->read(self::tags(['a' => 'A1', 'b' => 'B1']));

        try {
            $this->apply($set);
            self::fail('not interrupted');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('SEED_006', $e->errorCode());
            self::assertSame(1, $e->batchesCommitted);
            self::assertSame('partially_applied', $this->connection()->fetchOne('SELECT status FROM nowo_seed_run WHERE id = ?', [$e->runId]));
        }
        self::assertSame(['a' => 'A1', 'b' => 'B-edited'], $this->labels());

        $this->hooks()->beforeApply = null;
        $this->apply($set);
        self::assertSame(['a' => 'A1', 'b' => 'B1'], $this->labels(), 're-apply converges');
    }

    #[Test]
    public function aRowLockBeforeAnyCommitIsConflict001(): void
    {
        $this->other->beginTransaction();
        $this->other->fetchAllAssociative("SELECT label FROM host_tag WHERE scope_key = 'platform' AND slug = 'a' FOR UPDATE");

        try {
            $this->apply($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])));
            self::fail('no conflict');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('CONFLICT_001', $e->errorCode());
            self::assertSame(0, $e->batchesCommitted);
            self::assertInstanceOf(ConcurrencyConflict::class, $e->getPrevious());
            self::assertSame(3, $e->getPrevious()->attempts, 'replayed before giving up');
            self::assertNotNull($e->retryAfterSeconds);
            self::assertSame('failed', $this->connection()->fetchOne('SELECT status FROM nowo_seed_run WHERE id = ?', [$e->runId]));
        }
        $this->other->rollBack();
        self::assertSame(['a' => 'A0', 'b' => 'B0'], $this->labels(), 'nothing committed');
        self::assertSame(0, $this->connection()->getTransactionNestingLevel());

        $this->apply($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])));
        self::assertSame(['a' => 'A1', 'b' => 'B1'], $this->labels(), 'the connection is usable and the run converges');
    }

    #[Test]
    public function aRowLockAfterACommittedBatchIsConflict002(): void
    {
        $this->other->beginTransaction();
        $this->other->fetchAllAssociative("SELECT label FROM host_tag WHERE scope_key = 'platform' AND slug = 'b' FOR UPDATE");

        try {
            $this->apply($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])));
            self::fail('no conflict');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('CONFLICT_002', $e->errorCode());
            self::assertSame(1, $e->batchesCommitted);
            self::assertTrue($e->isPartial());
            self::assertSame('partially_applied', $this->connection()->fetchOne('SELECT status FROM nowo_seed_run WHERE id = ?', [$e->runId]));
        }
        $this->other->rollBack();
        self::assertSame(['a' => 'A1', 'b' => 'B0'], $this->labels(), 'batch 1 stays');

        $this->apply($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])));
        self::assertSame(['a' => 'A1', 'b' => 'B1'], $this->labels());
        self::assertSame(1, TestDatabase::int($this->other->fetchOne('SELECT 1')));
    }
}
