<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Nowo\SeedKitBundle\Application\ApplyOutcome;
use Nowo\SeedKitBundle\Application\SeedRuns;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use Nowo\TransactionContract\ConcurrencyConflict;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;

/**
 * REQ-RUNTIME-003 for the stored runs of the UI and Messenger (contract 10.3), with two connections on a real database
 * (MySQL, MariaDB, PostgreSQL; skipped on SQLite): the run claim lets one request / worker in, a batch recorded by
 * another worker makes ours roll back (a batch is never applied twice), a lock on the run row makes the claim give up
 * with a conflict and nothing written, a crashed worker's run is taken over after the lease. The connection stays
 * usable and the run converges.
 */
final class StoredRunConcurrencyTest extends ApplyTestCase
{
    private Connection $other;

    private DbalRunStore $otherRuns;

    protected function setUp(): void
    {
        if (!TestDatabase::isServer()) {
            self::markTestSkipped('Two-connection race tests need MySQL, MariaDB or PostgreSQL (CI "Databases" job).');
        }
        // The web channel without a security user: the demo checker grants it (the access rules are tested elsewhere).
        $this->boot(['apply' => ['batch_size' => 1], 'messenger' => ['trust_run_actor' => true], 'security' => ['access_checker' => 'allow_all', 'allow_unauthenticated' => true]]);
        TestDatabase::shortLockWait($this->connection());
        $this->other     = TestDatabase::connection();
        $this->otherRuns = new DbalRunStore($this->other, new TableNames(), new MockClock('2026-11-24 10:00:00', 'UTC'));
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

    private function runs(): SeedRuns
    {
        return $this->service(SeedRuns::class, SeedRuns::class);
    }

    #[Test]
    public function aRunClaimedOnAnotherConnectionIsBusy(): void
    {
        $run = $this->runs()->upload($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])), SeedScope::platform(), new ActorRef('ops'), false);

        self::assertTrue($this->otherRuns->transition($run->id, $run->version, [RunStatus::Planned], RunStatus::Running), 'the other request wins');
        self::assertSame(ApplyOutcome::BUSY, $this->runs()->apply($run->id, $run->planHash, SeedScope::platform(), new ActorRef('ops'))->kind);
        self::assertSame([], $this->labels(), 'nothing written by the loser');
    }

    #[Test]
    public function aBatchRecordedByAnotherWorkerIsNeverAppliedTwice(): void
    {
        $run                        = $this->runs()->upload($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])), SeedScope::platform(), new ActorRef('ops'), false);
        $other                      = $this->otherRuns;
        $done                       = false;
        $this->hooks()->beforeApply = static function (string $provider, AppliedBatch $batch) use ($other, $run, &$done): void {
            if ($batch->number === 1 && !$done) {
                // A redelivered message's worker records batch 1 on its own connection first.
                $done = $other->markBatch($run->id, 1, ['create' => 1, 'update' => 0, 'delete' => 0]);
            }
        };

        try {
            $this->runs()->apply($run->id, $run->planHash, SeedScope::platform(), new ActorRef('ops'));
            self::fail('our batch must roll back');
        } catch (ApplyInterruptedException $e) {
            self::assertTrue($done);
            self::assertSame('CONFLICT_001', $e->errorCode(), 'this attempt committed nothing');
            self::assertSame(0, $e->batchesCommitted);
        }
        self::assertSame([], $this->labels(), 'our write of batch 1 was rolled back');
        self::assertSame(1, TestDatabase::int($this->connection()->fetchOne('SELECT batches_done FROM nowo_seed_run')));
    }

    #[Test]
    public function aLockOnTheRunRowMakesTheClaimGiveUp(): void
    {
        $run = $this->runs()->upload($this->read(self::tags(['a' => 'A1'])), SeedScope::platform(), new ActorRef('ops'), false);
        $this->other->beginTransaction();
        $this->other->fetchAllAssociative('SELECT status FROM nowo_seed_run WHERE id = ? FOR UPDATE', [$run->id->value]);

        try {
            $this->runs()->apply($run->id, $run->planHash, SeedScope::platform(), new ActorRef('ops'));
            self::fail('no conflict');
        } catch (ConcurrencyConflict $e) {
            self::assertSame(3, $e->attempts, 'replayed before giving up');
        }
        $this->other->rollBack();
        self::assertSame([], $this->labels());

        $outcome = $this->runs()->apply($run->id, $run->planHash, SeedScope::platform(), new ActorRef('ops'));
        self::assertSame(ApplyOutcome::APPLIED, $outcome->kind, 'the connection is usable and the run applies');
        self::assertSame(['a' => 'A1'], $this->labels());
    }

    #[Test]
    public function aCrashedWorkersRunIsTakenOverAfterTheLease(): void
    {
        $run = $this->runs()->upload($this->read(self::tags(['a' => 'A1', 'b' => 'B1'])), SeedScope::platform(), new ActorRef('ops'), false);
        self::assertTrue($this->otherRuns->transition($run->id, $run->version, [RunStatus::Planned], RunStatus::Queued));
        self::assertTrue($this->otherRuns->transition($run->id, null, [RunStatus::Queued], RunStatus::Running), 'a worker claimed it');
        $this->other->executeStatement('UPDATE nowo_seed_run SET updated_at = ? WHERE id = ?', ['2026-11-24 08:00:00', $run->id->value]);

        $outcome = $this->runs()->applyQueued($run->id, $run->planHash);
        self::assertSame(ApplyOutcome::APPLIED, $outcome?->kind);
        self::assertSame(['a' => 'A1', 'b' => 'B1'], $this->labels());
        self::assertNull($this->runs()->applyQueued($run->id, $run->planHash), 'redelivered afterwards: a no-op');
        self::assertInstanceOf(DateTimeImmutable::class, $this->runs()->progress($run->id, SeedScope::platform(), new ActorRef('ops'))->finishedAt);
    }
}
