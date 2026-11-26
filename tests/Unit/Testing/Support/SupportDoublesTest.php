<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Testing\Support;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Testing\Support\DirectWriteRunner;
use Nowo\SeedKitBundle\Testing\Support\GrantAllGate;
use Nowo\SeedKitBundle\Testing\Support\InMemoryLeaseStore;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use Nowo\SeedKitBundle\Testing\Support\SequentialIdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/*
 * The in-memory doubles shipped for host tests.
 */
use function sprintf;
use function str_repeat;

final class SupportDoublesTest extends TestCase
{
    #[Test]
    public function grantAllGateGrantsEverything(): void
    {
        $gate = new GrantAllGate();
        foreach (SeedAction::cases() as $action) {
            self::assertTrue($gate->isGranted($action, new ProviderName('demo.tags'), SeedScope::platform(), Channel::Http, new ActorRef('anyone')));
        }
    }

    #[Test]
    public function directWriteRunnerRunsTheFirstAttempt(): void
    {
        self::assertSame('attempt 1', (new DirectWriteRunner())->write(static fn (int $attempt): string => 'attempt ' . $attempt));
    }

    #[Test]
    public function sequentialIdsAreUuidV7Shaped(): void
    {
        $ids = new SequentialIdGenerator();
        self::assertSame('00000000-0000-7000-8000-000000000001', $ids->runId()->value);
        self::assertSame('00000000-0000-7000-8000-000000000002', $ids->runId()->value);
    }

    #[Test]
    public function leasesAreExclusivePerProviderAndScope(): void
    {
        $leases = new InMemoryLeaseStore();
        $tags   = new ProviderName('demo.tags');
        $one    = new RunId('00000000-0000-7000-8000-000000000001');
        $two    = new RunId('00000000-0000-7000-8000-000000000002');
        self::assertSame(0, $leases->acquire($tags, SeedScope::platform(), $one, 60));
        self::assertSame(0, $leases->acquire($tags, SeedScope::platform(), $one, 60), 're-entrant');
        self::assertSame(60, $leases->acquire($tags, SeedScope::platform(), $two, 60));
        self::assertSame(0, $leases->acquire($tags, SeedScope::parse('organization:o'), $two, 60), 'other scope');
        self::assertTrue($leases->renew($tags, SeedScope::platform(), $one, 60));
        self::assertFalse($leases->renew($tags, SeedScope::platform(), $two, 60));
        $leases->release($tags, SeedScope::platform(), $two);
        self::assertFalse($leases->renew($tags, SeedScope::platform(), $two, 60), 'another run cannot release');
        $leases->release($tags, SeedScope::platform(), $one);
        self::assertSame(0, $leases->acquire($tags, SeedScope::platform(), $two, 60));
    }

    #[Test]
    public function runStoreMarksBatchesInOrderOnly(): void
    {
        $store = new InMemoryRunStore();
        $id    = new RunId('00000000-0000-7000-8000-000000000001');
        self::assertFalse($store->markBatch($id, 1, []), 'unknown run');
        $store->create(new RunRecord($id, SeedScope::platform(), new ActorRef('a'), Channel::Console, RunStatus::Running, [], false, null, new PlanHash('sk1:' . str_repeat('a', 64)), [], 0, [], null, new DateTimeImmutable()));
        self::assertFalse($store->markBatch($id, 2, ['create' => 1]), 'a skipped batch');
        self::assertTrue($store->markBatch($id, 1, ['create' => 1]));
        self::assertFalse($store->markBatch($id, 1, ['create' => 1]), 'a replayed batch');
        $record = $store->find($id);
        self::assertNotNull($record);
        self::assertSame(1, $record->batchesDone);
        self::assertSame(['create' => 1], $record->counts);
        $store->finish(new RunId('00000000-0000-7000-8000-000000000009'), RunStatus::Failed, 'X');
        self::assertSame(1, $store->count());
        $store->finish($id, RunStatus::Applied, null);
        self::assertSame(RunStatus::Applied, $store->all()[0]->status);
        self::assertNotNull($store->all()[0]->finishedAt);
    }

    #[Test]
    public function runStoreExpiresDocumentsLikeTheDatabase(): void
    {
        $store = new InMemoryRunStore();
        $make  = static fn (int $n, RunStatus $status, ?DateTimeImmutable $expires): RunRecord => new RunRecord(new RunId(sprintf('01936f2a-0000-7000-8000-%012d', $n)), SeedScope::platform(), new ActorRef('a'), Channel::Http, $status, [], false, null, new PlanHash('sk1:' . str_repeat('b', 64)), [], 0, [], null, new DateTimeImmutable('2026-11-24T10:00:00Z'), null, null, 1, $expires, true);
        $store->create($make(1, RunStatus::Planned, new DateTimeImmutable('2026-11-24T11:00:00Z')), 'gz');
        $store->create($make(2, RunStatus::PartiallyApplied, new DateTimeImmutable('2026-11-24T11:00:00Z')), 'gz');
        $store->create($make(3, RunStatus::Running, new DateTimeImmutable('2026-11-24T11:00:00Z')), 'gz');
        $store->create($make(4, RunStatus::Planned, null), 'gz');
        $store->create($make(5, RunStatus::Applied, null));

        self::assertSame(2, $store->expire(new DateTimeImmutable('2026-11-24T12:00:00Z'), 10));
        self::assertSame(RunStatus::Expired, $store->find(new RunId('01936f2a-0000-7000-8000-000000000001'))?->status);
        self::assertSame(RunStatus::PartiallyApplied, $store->find(new RunId('01936f2a-0000-7000-8000-000000000002'))?->status);
        self::assertNull($store->document(new RunId('01936f2a-0000-7000-8000-000000000002')));
        self::assertNotNull($store->document(new RunId('01936f2a-0000-7000-8000-000000000003')));
        $store->touch(new RunId('01936f2a-0000-7000-8000-000000000099'), new DateTimeImmutable());
        self::assertSame(0, $store->purge(new DateTimeImmutable('2026-11-24T09:00:00Z'), 10));
        self::assertTrue($store->transition(new RunId('01936f2a-0000-7000-8000-000000000003'), null, [RunStatus::Running], RunStatus::Failed, 'SEED_010', new DateTimeImmutable('2026-11-25T00:00:00Z')));
        self::assertFalse($store->transition(new RunId('01936f2a-0000-7000-8000-000000000098'), null, [RunStatus::Running], RunStatus::Failed));
        self::assertSame(1, $store->forgetActor(new ActorRef('a'), 10) > 0 ? 1 : 0);
    }
}
