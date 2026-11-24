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

/**
 * The in-memory doubles shipped for host tests.
 */
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
}
