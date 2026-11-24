<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function str_repeat;
use function substr;

/**
 * The run history through DBAL (contract section 11) on the real database: round trip, batch progress that a replay
 * cannot count twice, keyset listing, batched purge and actor erasure, the table prefix (REQ-ORM-002).
 */
final class DbalRunStoreTest extends DatabaseTestCase
{
    #[Test]
    public function aRunRoundTrips(): void
    {
        $organization = new SeedScope(new ScopeKind('organization'), new ScopeId('Acme'));
        $this->runs->create($this->record(2, RunStatus::Running, 'Bob', $organization, new DateTimeImmutable('2026-11-24 11:30:00', new DateTimeZone('Europe/Madrid'))));

        $run = $this->runs->find(self::runId(2));
        self::assertNotNull($run);
        self::assertSame('01936f2a-0000-7000-8000-000000000002', $run->id->value);
        self::assertSame('organization:Acme', $run->scope->key());
        self::assertSame('Bob', $run->actor?->value);
        self::assertSame(Channel::Console, $run->channel);
        self::assertSame(RunStatus::Running, $run->status);
        self::assertEquals(['host.tags' => 1, 'host.articles' => 2], $run->providers, 'MySQL stores JSON objects with its own key order');
        self::assertTrue($run->prune);
        self::assertSame('sha256:' . str_repeat('a', 64), $run->documentHash?->value);
        self::assertSame('sk1:' . str_repeat('b', 64), $run->planHash->value);
        self::assertSame(['d1', 'd2'], $run->batchDigests);
        self::assertSame(0, $run->batchesDone);
        self::assertEquals(['create' => 0, 'update' => 0, 'delete' => 0], $run->counts);
        self::assertNull($run->errorCode);
        self::assertSame('2026-11-24 10:30:00 UTC', $run->createdAt->format('Y-m-d H:i:s T'), 'stored in UTC');
        self::assertNull($run->finishedAt);
        self::assertNull($this->runs->find(self::runId(99)));
    }

    #[Test]
    public function batchProgressIsCountedOnce(): void
    {
        $this->runs->create($this->record(1));

        self::assertTrue($this->runs->markBatch(self::runId(1), 1, ['create' => 2, 'update' => 0, 'delete' => 0]));
        self::assertFalse($this->runs->markBatch(self::runId(1), 1, ['create' => 4, 'update' => 0, 'delete' => 0]), 'a replayed batch');
        self::assertFalse($this->runs->markBatch(self::runId(1), 3, ['create' => 9, 'update' => 0, 'delete' => 0]), 'a skipped batch');
        self::assertTrue($this->runs->markBatch(self::runId(1), 2, ['create' => 2, 'update' => 1, 'delete' => 0]));

        $run = $this->runs->find(self::runId(1));
        self::assertSame(2, $run?->batchesDone);
        self::assertEquals(['create' => 2, 'update' => 1, 'delete' => 0], $run->counts);
        self::assertSame(3, TestDatabase::int($this->connection->fetchOne('SELECT version FROM nowo_seed_run')));
    }

    #[Test]
    public function finishingSetsTheStatusTheErrorAndClearsTheDocument(): void
    {
        $this->runs->create($this->record(1));
        $this->connection->executeStatement("UPDATE nowo_seed_run SET document = 'gz'");
        $this->clock->modify('+5 minutes');

        $this->runs->finish(self::runId(1), RunStatus::PartiallyApplied, 'SEED_006');

        $run = $this->runs->find(self::runId(1));
        self::assertSame(RunStatus::PartiallyApplied, $run?->status);
        self::assertSame('SEED_006', $run->errorCode);
        self::assertSame('2026-11-24 09:05:00', $run->finishedAt?->format('Y-m-d H:i:s'));
        self::assertNull($this->connection->fetchOne('SELECT document FROM nowo_seed_run'));
    }

    #[Test]
    public function theListFiltersAndPagesByKeyset(): void
    {
        $acme = new SeedScope(new ScopeKind('organization'), new ScopeId('acme'));
        $this->runs->create($this->record(1, RunStatus::Applied));
        $this->runs->create($this->record(2, RunStatus::Failed, null));
        $this->runs->create($this->record(3, RunStatus::Applied, 'bob', $acme));
        $this->runs->create($this->record(4, RunStatus::Applied));

        self::assertSame(['04', '03', '02', '01'], self::ids($this->runs->list(null, null, 10)));
        self::assertSame(['04', '03'], self::ids($this->runs->list(null, null, 2)));
        self::assertSame(['02', '01'], self::ids($this->runs->list(null, null, 2, self::runId(3))));
        self::assertSame(['04', '01'], self::ids($this->runs->list(SeedScope::platform(), RunStatus::Applied, 10)));
        self::assertSame(['03'], self::ids($this->runs->list($acme, null, 10)));
        self::assertSame(['01'], self::ids($this->runs->list(SeedScope::platform(), RunStatus::Applied, 10, self::runId(4))));
        self::assertNull($this->runs->list(null, RunStatus::Failed, 1)[0]->actor);
    }

    /**
     * The last two digits of the run ids.
     *
     * @param list<RunRecord> $runs
     *
     * @return list<string>
     */
    private static function ids(array $runs): array
    {
        return array_map(static fn (RunRecord $run): string => substr($run->id->value, -2), $runs);
    }

    #[Test]
    public function thePurgeDeletesFinishedOldRunsInBatches(): void
    {
        $old = new DateTimeImmutable('2025-01-01 00:00:00');
        foreach ([1 => RunStatus::Applied, 2 => RunStatus::PartiallyApplied, 3 => RunStatus::Failed, 4 => RunStatus::Discarded, 5 => RunStatus::Expired, 6 => RunStatus::Running, 7 => RunStatus::Planned] as $number => $status) {
            $this->runs->create($this->record($number, $status, 'alice', null, $old));
        }
        $this->runs->create($this->record(8, RunStatus::Applied));
        $before = new DateTimeImmutable('2026-01-01');

        self::assertSame(5, $this->runs->countPurgeable($before));
        self::assertSame(5, $this->runs->purge($before, 2));
        self::assertSame(0, $this->runs->countPurgeable($before));
        self::assertSame(0, $this->runs->purge($before, 2));
        self::assertSame(['01936f2a-0000-7000-8000-000000000006', '01936f2a-0000-7000-8000-000000000007', '01936f2a-0000-7000-8000-000000000008'], $this->connection->fetchFirstColumn('SELECT id FROM nowo_seed_run ORDER BY id'));
    }

    #[Test]
    public function forgettingAnActorNullsItInBatches(): void
    {
        foreach ([1, 2, 3] as $number) {
            $this->runs->create($this->record($number, RunStatus::Applied, 'alice'));
        }
        $this->runs->create($this->record(4, RunStatus::Applied, 'Alice'));
        $this->runs->create($this->record(5, RunStatus::Applied, 'bob'));

        self::assertSame(3, $this->runs->forgetActor(new ActorRef('alice'), 2));
        self::assertSame(0, $this->runs->forgetActor(new ActorRef('alice'), 2));
        self::assertSame([null, null, null, 'Alice', 'bob'], $this->connection->fetchFirstColumn('SELECT actor_ref FROM nowo_seed_run ORDER BY id'));
    }

    #[Test]
    public function oddColumnValuesAreReadSafely(): void
    {
        $this->connection->insert('nowo_seed_run', [
            'id'            => '01936f2a-0000-7000-8000-000000000001', 'scope_kind' => 'platform', 'scope_id' => '', 'actor_ref' => null, 'channel' => 'http', 'status' => 'planned',
            'providers'     => '{"a.b":"x","c.d":3}', 'prune' => 0, 'document_hash' => null, 'plan_hash' => 'sk1:' . str_repeat('c', 64),
            'batch_digests' => '{"k":"v"}', 'batches_done' => 0, 'counts' => '"text"', 'version' => 1, 'created_at' => '2026-11-24 10:00:00', 'updated_at' => '2026-11-24 10:00:00',
        ]);

        $run = $this->runs->find(self::runId(1));
        self::assertNotNull($run);
        self::assertNull($run->scope->id);
        self::assertSame(['c.d' => 3], $run->providers);
        self::assertSame([], $run->batchDigests);
        self::assertSame([], $run->counts);
        self::assertNull($run->documentHash);
        self::assertFalse($run->prune);
    }

    #[Test]
    public function theStoreWritesThePrefixedTable(): void
    {
        [$connection] = TestDatabase::fresh('attribute', 'acme_');
        $store        = new DbalRunStore($connection, new TableNames('acme_'), $this->clock);
        $store->create($this->record(1));

        self::assertSame(1, TestDatabase::int($connection->fetchOne('SELECT COUNT(*) FROM acme_seed_run')));
        self::assertSame('alice', $store->find(self::runId(1))?->actor?->value);
        $connection->close();
    }
}
