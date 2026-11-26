<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\BatchManifest;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

use function gzencode;
use function str_repeat;

/**
 * The stored runs of the 0.2 UI on the real database (contract sections 10.3 and 11): the gzip document as a binary
 * large object, the batch layout, the reuse of a planned run, the compare-and-set claim (version, statuses, stale
 * `running` takeover), the expiry of documents and the listing of one actor's runs.
 */
final class DbalStoredRunTest extends DatabaseTestCase
{
    #[Test]
    public function aPlannedRunKeepsItsDocumentAndLayout(): void
    {
        $bytes = (string) gzencode("{\"v\":1}\n\0\xff binary", 6);
        $this->runs->create($this->planned(1), $bytes);

        $run = $this->runs->find(self::runId(1));
        self::assertNotNull($run);
        self::assertTrue($run->hasDocument);
        self::assertSame(RunStatus::Planned, $run->status);
        self::assertSame(1, $run->version);
        self::assertSame('2026-11-24 10:00:00', $run->expiresAt?->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-24 09:00:00', $run->updatedAt?->format('Y-m-d H:i:s'));
        self::assertSame($bytes, $this->runs->document(self::runId(1)), 'binary bytes round-trip (bytea, LONGBLOB, BLOB)');
        self::assertSame(['d1', 'd2', 'd3'], $run->batchDigests);
        self::assertEquals(new BatchManifest(['host.tags' => [['d1', true], ['d2', false]], 'host.articles' => [['d3', true]]]), $run->manifest);
        self::assertTrue($run->isMassPrune() === false && $run->changes() === 3);
        self::assertNull($this->runs->document(self::runId(9)));
    }

    #[Test]
    public function aPlannedRunIsReusedOnlyWhileItMatchesAndLives(): void
    {
        $this->runs->create($this->planned(1), 'gz');
        $now  = new DateTimeImmutable('2026-11-24 09:30:00');
        $doc  = new DocumentHash('sha256:' . str_repeat('a', 64));
        $plan = new PlanHash('sk1:' . str_repeat('b', 64));

        self::assertSame(self::runId(1)->value, $this->runs->findPlanned(new ActorRef('alice'), SeedScope::platform(), $doc, $plan, false, $now)?->id->value);
        self::assertNull($this->runs->findPlanned(new ActorRef('Alice'), SeedScope::platform(), $doc, $plan, false, $now), 'exact actor');
        self::assertNull($this->runs->findPlanned(new ActorRef('alice'), SeedScope::platform(), $doc, $plan, true, $now), 'prune differs');
        self::assertNull($this->runs->findPlanned(new ActorRef('alice'), SeedScope::platform(), $doc, new PlanHash('sk1:' . str_repeat('c', 64)), false, $now), 'data changed');
        self::assertNull($this->runs->findPlanned(new ActorRef('alice'), SeedScope::platform(), $doc, $plan, false, new DateTimeImmutable('2026-11-24 11:00:00')), 'expired');
    }

    #[Test]
    public function theClaimIsACompareAndSet(): void
    {
        $this->runs->create($this->planned(1), 'gz');

        self::assertFalse($this->runs->transition(self::runId(1), 1, [], RunStatus::Running), 'no source status');
        self::assertFalse($this->runs->transition(self::runId(1), 2, [RunStatus::Planned], RunStatus::Running), 'another version');
        self::assertFalse($this->runs->transition(self::runId(1), 1, [RunStatus::Queued], RunStatus::Running), 'another status');
        self::assertTrue($this->runs->transition(self::runId(1), 1, [RunStatus::Planned], RunStatus::Running));
        self::assertFalse($this->runs->transition(self::runId(1), 1, [RunStatus::Planned], RunStatus::Running), 'the second click loses');
        self::assertFalse($this->runs->transition(self::runId(1), null, [RunStatus::Running], RunStatus::Queued, null, null, new DateTimeImmutable('2026-11-24 08:00:00')), 'a fresh running run is not taken over');

        $this->clock->modify('+10 minutes');
        self::assertTrue($this->runs->transition(self::runId(1), null, [RunStatus::Running], RunStatus::Planned, 'CONFLICT_001', null, new DateTimeImmutable('2026-11-24 10:05:00')), 'stale after the lease');
        $run = $this->runs->find(self::runId(1));
        self::assertSame(RunStatus::Planned, $run?->status);
        self::assertSame('CONFLICT_001', $run->errorCode);
        self::assertSame(3, $run->version);
        self::assertTrue($run->hasDocument);

        self::assertTrue($this->runs->transition(self::runId(1), 3, [RunStatus::Planned], RunStatus::Queued, null, new DateTimeImmutable('2026-11-24 12:00:00')));
        self::assertSame('2026-11-24 12:00:00', $this->runs->find(self::runId(1))?->expiresAt?->format('Y-m-d H:i:s'));

        self::assertTrue($this->runs->transition(self::runId(1), null, [RunStatus::Queued], RunStatus::Discarded));
        $run = $this->runs->find(self::runId(1));
        self::assertSame(RunStatus::Discarded, $run?->status);
        self::assertFalse($run->hasDocument, 'a final status clears the document');
        self::assertNotNull($run->finishedAt);
    }

    #[Test]
    public function documentsExpire(): void
    {
        $this->runs->create($this->planned(1), 'gz');
        $this->runs->create($this->planned(2, RunStatus::Queued), 'gz');
        $this->runs->create($this->planned(3, RunStatus::PartiallyApplied), 'gz');
        $this->runs->create($this->planned(4, RunStatus::Running), 'gz');
        $this->runs->create($this->planned(5, RunStatus::Planned, '2026-11-24 12:00:00'), 'gz');
        $this->runs->create($this->record(6, RunStatus::Applied));

        self::assertSame(0, $this->runs->expire(new DateTimeImmutable('2026-11-24 09:59:00'), 2));
        self::assertSame(3, $this->runs->expire(new DateTimeImmutable('2026-11-24 10:30:00'), 1), 'batches of one');

        self::assertSame(RunStatus::Expired, $this->runs->find(self::runId(1))?->status);
        self::assertSame(RunStatus::Expired, $this->runs->find(self::runId(2))?->status);
        $partial = $this->runs->find(self::runId(3));
        self::assertSame(RunStatus::PartiallyApplied, $partial?->status, 'a partial run keeps its status');
        self::assertFalse($partial->hasDocument);
        self::assertTrue($this->runs->find(self::runId(4))?->hasDocument, 'a running run is never expired');
        self::assertTrue($this->runs->find(self::runId(5))?->hasDocument);
        self::assertSame(0, $this->runs->expire(new DateTimeImmutable('2026-11-24 10:30:00'), 10));
        self::assertSame(2, TestDatabase::int($this->connection->fetchOne("SELECT COUNT(*) FROM nowo_seed_run WHERE status = 'expired' AND finished_at IS NOT NULL")));
    }

    #[Test]
    public function theListCanBeLimitedToOneActor(): void
    {
        $this->runs->create($this->record(1, RunStatus::Applied, 'alice'));
        $this->runs->create($this->record(2, RunStatus::Applied, 'bob'));
        $this->runs->create($this->record(3, RunStatus::Applied, 'Alice'));

        $runs = $this->runs->list(SeedScope::platform(), null, 10, null, new ActorRef('alice'));
        self::assertCount(1, $runs);
        self::assertSame(self::runId(1)->value, $runs[0]->id->value);
    }

    /**
     * A planned UI run with a layout, expiring at 10:00 UTC.
     */
    private function planned(int $number, RunStatus $status = RunStatus::Planned, string $expires = '2026-11-24 10:00:00'): RunRecord
    {
        $manifest = new BatchManifest(['host.tags' => [['d1', true], ['d2', false]], 'host.articles' => [['d3', true]]]);

        return new RunRecord(
            self::runId($number),
            SeedScope::platform(),
            new ActorRef('alice'),
            Channel::Http,
            $status,
            ['host.tags' => 1, 'host.articles' => 1],
            false,
            new DocumentHash('sha256:' . str_repeat('a', 64)),
            new PlanHash('sk1:' . str_repeat('b', 64)),
            $manifest->flat(),
            0,
            ['create' => 0, 'update' => 0, 'delete' => 0, 'planned_create' => 2, 'planned_update' => 1, 'planned_delete' => 0, 'planned_unchanged' => 4, 'mass_prune' => 0],
            null,
            DateTimeImmutable::createFromInterface($this->clock->now()),
            null,
            $manifest,
            1,
            new DateTimeImmutable($expires . ' UTC'),
            true,
        );
    }
}
