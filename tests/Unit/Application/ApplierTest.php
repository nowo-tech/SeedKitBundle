<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\SeedKitBundle\Application\Applier;
use Nowo\SeedKitBundle\Application\ApplyResult;
use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Event\SeedBatchCommittedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunAppliedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunFailedEvent;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Testing\Support\InMemoryLeaseStore;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\HookedWriteRunner;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingDispatcher;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;

use const DATE_ATOM;

/**
 * The apply (contract 10): leases, re-plan, per-batch digest check under lock, run record, events, failures.
 */
final class ApplierTest extends TestCase
{
    private InMemoryRunStore $runs;

    private InMemoryLeaseStore $leases;

    private RecordingDispatcher $events;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->runs   = new InMemoryRunStore();
        $this->leases = new InMemoryLeaseStore();
        $this->events = new RecordingDispatcher();
        $this->logger = new RecordingLogger();
    }

    private function applier(Planner $planner, int $batchSize = 500, ?HookedWriteRunner $writer = null, ?LeaseStoreInterface $leases = null, ?InMemoryRunStore $runs = null): Applier
    {
        return Harness::applier($planner, Harness::settings(batchSize: $batchSize, pruneMaxRatio: 1.0), $writer, $leases ?? $this->leases, $runs ?? $this->runs, $this->logger, $this->events);
    }

    /**
     * @param list<ArticleProvider|ScriptedProvider|TagProvider> $providers
     * @param list<SeedDocument> $documents
     */
    private function apply(array $providers, array $documents, int $batchSize = 500, ?HookedWriteRunner $writer = null, PruneOptions $prune = new PruneOptions(), ?PlanHash $expected = null, ?SeedScope $scope = null, ?LeaseStoreInterface $leases = null, ?InMemoryRunStore $runs = null): ApplyResult
    {
        $scope ??= Harness::platform();
        $planner = Harness::planner($providers, Harness::settings(batchSize: $batchSize, pruneMaxRatio: 1.0));
        $set     = Harness::set($documents);

        return $this->applier($planner, $batchSize, $writer, $leases, $runs)->apply($set, $planner->prepare($set, $scope), Harness::context($scope, prune: $prune->prune), $prune, $expected);
    }

    private function onlyRun(): RunRecord
    {
        self::assertSame(1, $this->runs->count());

        return $this->runs->all()[0];
    }

    #[Test]
    public function aPlanWithoutChangesWritesNothing(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('a', ['label' => 'A']);
        $writer = new HookedWriteRunner();
        $result = $this->apply([$provider], [Harness::document($provider, [['code' => 'a', 'label' => 'A']])], writer: $writer);
        self::assertNull($result->runId);
        self::assertSame(0, $result->batches);
        self::assertSame([], $result->written);
        self::assertSame(0, $result->plan->changes());
        self::assertSame(0, $writer->calls);
        self::assertSame(0, $this->runs->count());
        self::assertSame([], $this->events->events);
        self::assertSame(0, $this->leases->acquire(new ProviderName('demo.items'), Harness::platform(), new RunId('00000000-0000-7000-8000-000000000099'), 10), 'lease released');
    }

    #[Test]
    public function appliesWritingBatchesAndRecordsTheRun(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('c', ['label' => 'C']);
        $provider->seed('d', ['label' => 'D']);
        $items  = [['code' => 'a', 'label' => 'A'], ['code' => 'b', 'label' => 'B'], ['code' => 'c', 'label' => 'C'], ['code' => 'd', 'label' => 'D'], ['code' => 'e', 'label' => 'E']];
        $result = $this->apply([$provider], [Harness::document($provider, $items)], batchSize: 2);

        self::assertNotNull($result->runId);
        self::assertSame('00000000-0000-7000-8000-000000000001', $result->runId->value);
        self::assertSame(2, $result->batches, 'the unchanged-only slice is skipped');
        self::assertSame(['create' => 3, 'update' => 0, 'delete' => 0], $result->written);
        self::assertSame(['plan:2', 'plan:2', 'plan:1', 'plan:2:lock', 'apply:1:C["a"],C["b"]', 'after', 'plan:1:lock', 'apply:2:C["e"]', 'after'], $provider->log);
        self::assertSame(['["c"]', '["d"]', '["a"]', '["b"]', '["e"]'], array_keys($provider->rows));

        $run = $this->onlyRun();
        self::assertSame(RunStatus::Applied, $run->status);
        self::assertSame(2, $run->batchesDone);
        self::assertSame(['create' => 3, 'update' => 0, 'delete' => 0], $run->counts);
        self::assertSame(['demo.items' => 1], $run->providers);
        self::assertCount(3, $run->batchDigests);
        self::assertSame($result->plan->hash->value, $run->planHash->value);
        self::assertSame('alice', $run->actor?->value);
        self::assertNull($run->errorCode);
        self::assertNotNull($run->finishedAt);
        self::assertSame('2026-11-24T10:00:00+00:00', $run->createdAt->format(DATE_ATOM));

        self::assertSame(['SeedBatchCommittedEvent', 'SeedBatchCommittedEvent', 'SeedRunAppliedEvent'], $this->events->names());
        $batch = $this->events->events[1];
        self::assertInstanceOf(SeedBatchCommittedEvent::class, $batch);
        self::assertSame([2, 1, 0, 0], [$batch->batchNumber, $batch->created, $batch->updated, $batch->deleted]);
        $applied = $this->events->events[2];
        self::assertInstanceOf(SeedRunAppliedEvent::class, $applied);
        self::assertSame(['demo.items'], $applied->providers);
        self::assertSame($result->runId, $applied->runId);

        // the leases are released
        self::assertSame(0, $this->leases->acquire(new ProviderName('demo.items'), Harness::platform(), new RunId('00000000-0000-7000-8000-000000000099'), 10));
    }

    #[Test]
    public function createdKeysArePendingForLaterDocuments(): void
    {
        $store    = new InMemoryStore();
        $tags     = new TagProvider($store);
        $articles = new ArticleProvider($store);
        $result   = $this->apply([$tags, $articles], [
            Harness::document($articles, [['code' => 'a1', 'title' => 'T', 'tag' => 'news']], 'organization'),
            Harness::document($tags, [['slug' => 'news', 'label' => 'News']], 'organization'),
        ], scope: Harness::org());
        self::assertSame(['create' => 2, 'update' => 0, 'delete' => 0], $result->written);
        self::assertArrayHasKey('["a1"]', $store->rows['demo.articles']);
        self::assertSame(['demo.tags' => 1, 'demo.articles' => 1], $this->onlyRun()->providers);
    }

    #[Test]
    public function aHeldLeaseStopsBeforeAnythingAndReleasesTheOthers(): void
    {
        $first  = new ScriptedProvider('demo.alpha');
        $second = new ScriptedProvider('demo.beta');
        $other  = new RunId('00000000-0000-7000-8000-0000000000ff');
        $this->leases->acquire(new ProviderName('demo.beta'), Harness::platform(), $other, 300);
        try {
            $this->apply([$second, $first], [Harness::document($first, [['code' => 'a']]), Harness::document($second, [['code' => 'b']])]);
            self::fail('expected');
        } catch (LeaseHeldException $e) {
            self::assertSame(300, $e->retryAfterSeconds);
        }
        self::assertSame([], $first->log, 'nothing planned');
        self::assertSame(0, $this->runs->count());
        self::assertSame(0, $this->leases->acquire(new ProviderName('demo.alpha'), Harness::platform(), $other, 10), 'the alpha lease was released');
    }

    #[Test]
    public function aStalePlanWritesNothing(): void
    {
        $provider = new ScriptedProvider();
        $writer   = new HookedWriteRunner();
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])], writer: $writer, expected: new PlanHash('sk1:' . str_repeat('0', 64)));
            self::fail('expected');
        } catch (StalePlanException) {
        }
        self::assertSame(0, $writer->calls);
        self::assertSame([], $provider->rows);
        self::assertSame(0, $this->leases->acquire(new ProviderName('demo.items'), Harness::platform(), new RunId('00000000-0000-7000-8000-000000000099'), 10));
    }

    #[Test]
    public function theReviewedHashIsAccepted(): void
    {
        $provider = new ScriptedProvider();
        $planner  = Harness::planner([$provider]);
        $set      = Harness::set([Harness::document($provider, [['code' => 'a']])]);
        $docs     = $planner->prepare($set, Harness::platform());
        $hash     = $planner->plan($docs, Harness::context(), new PruneOptions())->hash;
        $result   = $this->applier($planner)->apply($set, $docs, Harness::context(), new PruneOptions(), $hash);
        self::assertTrue($result->plan->hash->equals($hash));
        self::assertSame(1, $result->batches);
    }

    #[Test]
    public function aRowChangedInTheFirstBatchFailsTheRun(): void
    {
        $provider = new ScriptedProvider();
        $writer   = new HookedWriteRunner(static function (int $call) use ($provider): void {
            if ($call === 2) {
                $provider->seed('a', ['label' => 'sneaky']);
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a'], ['code' => 'b']])], batchSize: 1, writer: $writer);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::ROW_CHANGED, $e->kind);
            self::assertFalse($e->isPartial());
            self::assertSame([0, 2], [$e->batchesCommitted, $e->batchesTotal]);
            self::assertSame('00000000-0000-7000-8000-000000000001', $e->runId);
        }
        $run = $this->onlyRun();
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame('SEED_006', $run->errorCode);
        self::assertSame(['SeedRunFailedEvent'], $this->events->names());
        self::assertSame(['["a"]' => ['code' => 'a', 'label' => 'sneaky']], $provider->rows, 'nothing applied');
    }

    #[Test]
    public function aRowChangedInALaterBatchIsPartial(): void
    {
        $provider = new ScriptedProvider();
        $writer   = new HookedWriteRunner(static function (int $call) use ($provider): void {
            if ($call === 3) {
                $provider->seed('b', ['label' => 'sneaky']);
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a'], ['code' => 'b']])], batchSize: 1, writer: $writer);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('SEED_006', $e->errorCode());
            self::assertTrue($e->isPartial());
            self::assertSame(1, $e->batchesCommitted);
        }
        self::assertSame(RunStatus::PartiallyApplied, $this->onlyRun()->status);
        self::assertSame(1, $this->onlyRun()->batchesDone);
        $failed = $this->events->events[1];
        self::assertInstanceOf(SeedRunFailedEvent::class, $failed);
        self::assertSame(['SEED_006', 1], [$failed->errorCode, $failed->batchesCommitted]);
    }

    #[Test]
    public function aProviderFailureIsWrappedAndLogged(): void
    {
        $provider               = new ScriptedProvider();
        $provider->onApplyBatch = static function (): BatchResult {
            throw new RuntimeException('secret detail', 42);
        };
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])]);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::PROVIDER_FAILED, $e->kind);
            self::assertInstanceOf(RuntimeException::class, $e->getPrevious());
            self::assertStringNotContainsString('secret detail', $e->getMessage());
        }
        self::assertSame(RunStatus::Failed, $this->onlyRun()->status);
        self::assertSame('SEED_010', $this->onlyRun()->errorCode);
        self::assertSame('error', $this->logger->records[0][0]);
        self::assertSame(['run' => '00000000-0000-7000-8000-000000000001', 'exception_class' => RuntimeException::class, 'exception_code' => '42'], $this->logger->records[0][2], 'class and code only, never the exception (ZBK L1)');
    }

    #[Test]
    public function aBatchTheRunAlreadyRecordedRollsBackAsAConflict(): void
    {
        $provider = new ScriptedProvider();
        $runs     = $this->runs;
        $writer   = new HookedWriteRunner(static function (int $call) use ($runs): void {
            if ($call === 2) {
                // Another delivery of the same run recorded batch 1 first (ZBK L2).
                self::assertTrue($runs->markBatch(new RunId('00000000-0000-7000-8000-000000000001'), 1, ['create' => 9, 'update' => 0, 'delete' => 0]));
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a'], ['code' => 'b']])], batchSize: 1, writer: $writer);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::CONFLICT, $e->kind);
            self::assertSame('CONFLICT_001', $e->errorCode());
            self::assertSame([0, 2], [$e->batchesCommitted, $e->batchesTotal]);
        }
        $run = $this->onlyRun();
        self::assertSame(RunStatus::Failed, $run->status);
        self::assertSame('CONFLICT_001', $run->errorCode);
        self::assertSame(1, $run->batchesDone, 'the other delivery\'s progress is kept');
        self::assertSame(['create' => 9, 'update' => 0, 'delete' => 0], $run->counts);
        self::assertSame(['SeedRunFailedEvent'], $this->events->names(), 'no batch event for the refused batch');
    }

    #[Test]
    public function aProviderFailureAfterABatchIsPartial(): void
    {
        $provider               = new ScriptedProvider();
        $calls                  = 0;
        $provider->onApplyBatch = static function ($context, AppliedBatch $batch) use (&$calls): BatchResult {
            if (++$calls === 2) {
                throw new LogicException('boom');
            }

            return new BatchResult(count($batch->changes));
        };
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a'], ['code' => 'b']])], batchSize: 1);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame([1, 2], [$e->batchesCommitted, $e->batchesTotal]);
        }
        self::assertSame(RunStatus::PartiallyApplied, $this->onlyRun()->status);
    }

    #[Test]
    public function aLockConflictBecomesAConflictInterruption(): void
    {
        $provider = new ScriptedProvider();
        $writer   = new HookedWriteRunner(static function (int $call): void {
            if ($call === 2) {
                throw new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 7, new RuntimeException('deadlock'));
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])], writer: $writer);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::CONFLICT, $e->kind);
            self::assertSame('CONFLICT_001', $e->errorCode());
            self::assertSame(7, $e->retryAfterSeconds);
            self::assertInstanceOf(ConcurrencyConflict::class, $e->getPrevious());
        }
        self::assertSame('CONFLICT_001', $this->onlyRun()->errorCode);
        self::assertSame([], $this->logger->records, 'a conflict is not a provider failure');
    }

    #[Test]
    public function aLostLeaseStopsAfterTheCommittedBatch(): void
    {
        $leases = new class implements LeaseStoreInterface {
            public int $released = 0;

            public function acquire(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): int
            {
                return 0;
            }

            public function renew(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): bool
            {
                return false;
            }

            public function release(ProviderName $provider, SeedScope $scope, RunId $run): void
            {
                ++$this->released;
            }
        };
        $provider = new ScriptedProvider();
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a'], ['code' => 'b']])], batchSize: 1, leases: $leases);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('CONFLICT_002', $e->errorCode());
            self::assertSame([1, 2, 1], [$e->batchesCommitted, $e->batchesTotal, $e->retryAfterSeconds]);
        }
        self::assertArrayHasKey('["a"]', $provider->rows);
        self::assertArrayNotHasKey('["b"]', $provider->rows);
        self::assertSame(1, $leases->released);
        self::assertSame(RunStatus::PartiallyApplied, $this->onlyRun()->status);
    }

    #[Test]
    public function aReplayedAttemptResetsTheProviderFirst(): void
    {
        $provider = new ScriptedProvider();
        $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])], writer: new HookedWriteRunner(null, 2));
        self::assertSame(['plan:1', 'after', 'plan:1:lock', 'apply:1:C["a"]', 'after'], $provider->log);
    }

    #[Test]
    public function pruneDeletesInReverseDependencyOrder(): void
    {
        $parent = new ScriptedProvider('demo.parents');
        $child  = new ScriptedProvider('demo.children', null, ['platform'], ['demo.parents']);
        foreach (['x', 'y', 'z'] as $code) {
            $parent->seed($code);
            $child->seed($code);
        }
        $result = $this->apply([$parent, $child], [
            Harness::document($child, [['code' => 'k']]),
            Harness::document($parent, [['code' => 'k']]),
        ], batchSize: 2, prune: new PruneOptions(true, true));

        self::assertSame(['create' => 2, 'update' => 0, 'delete' => 6], $result->written);
        $order = [];
        foreach ($this->events->events as $event) {
            if ($event instanceof SeedBatchCommittedEvent) {
                $order[] = $event->provider->value . '#' . $event->batchNumber . ':' . ($event->deleted > 0 ? 'D' . $event->deleted : 'C' . $event->created);
            }
        }
        self::assertSame([
            'demo.parents#1:C1',
            'demo.children#2:C1',
            'demo.children#3:D2',
            'demo.children#4:D1',
            'demo.parents#5:D2',
            'demo.parents#6:D1',
        ], $order);
        self::assertSame(['["k"]'], array_keys($parent->rows));
        self::assertSame(['["k"]'], array_keys($child->rows));
        self::assertTrue($this->onlyRun()->prune);
        self::assertSame(RunStatus::Applied, $this->onlyRun()->status);
    }

    #[Test]
    public function aDeletionThatVanishedIsARowChange(): void
    {
        $provider = new ScriptedProvider();
        $provider->seed('x');
        $writer = new HookedWriteRunner(static function (int $call) use ($provider): void {
            if ($call === 3) {
                unset($provider->rows['["x"]']);
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'k']])], writer: $writer, prune: new PruneOptions(true, true));
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame(ApplyInterruptedException::ROW_CHANGED, $e->kind);
            self::assertSame(1, $e->batchesCommitted);
        }
    }

    #[Test]
    public function aStoreFailureWhileFailingIsLoggedAndSwallowed(): void
    {
        $provider               = new ScriptedProvider();
        $provider->onApplyBatch = static function (): BatchResult {
            throw new RuntimeException('provider');
        };
        $writer = new HookedWriteRunner(static function (int $call): void {
            if ($call === 3) {
                throw new RuntimeException('store down');
            }
        });
        try {
            $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])], writer: $writer);
            self::fail('expected');
        } catch (ApplyInterruptedException $e) {
            self::assertSame('SEED_010', $e->errorCode());
        }
        self::assertSame(RunStatus::Running, $this->onlyRun()->status, 'the run could not be marked');
        self::assertSame(['error', 'warning'], array_map(static fn (array $log): string => $log[0], $this->logger->records));
        self::assertSame(['run' => '00000000-0000-7000-8000-000000000001', 'exception_class' => RuntimeException::class], $this->logger->records[1][2]);
        self::assertSame(['SeedRunFailedEvent'], $this->events->names());
    }

    #[Test]
    public function aLeaseReleaseFailureIsLoggedAndSwallowed(): void
    {
        $leases = new class implements LeaseStoreInterface {
            public function acquire(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): int
            {
                return 0;
            }

            public function renew(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): bool
            {
                return true;
            }

            public function release(ProviderName $provider, SeedScope $scope, RunId $run): void
            {
                throw new RuntimeException('lease store down');
            }
        };
        $provider = new ScriptedProvider();
        $result   = $this->apply([$provider], [Harness::document($provider, [['code' => 'a']])], leases: $leases);
        self::assertSame(1, $result->batches);
        self::assertSame('warning', $this->logger->records[0][0]);
        self::assertSame(['provider' => 'demo.items', 'exception_class' => RuntimeException::class], $this->logger->records[0][2]);
    }

    #[Test]
    public function worksWithoutLoggerAndDispatcher(): void
    {
        $provider               = new ScriptedProvider();
        $provider->onApplyBatch = static function (): BatchResult {
            throw new RuntimeException('x');
        };
        $planner = Harness::planner([$provider]);
        $set     = Harness::set([Harness::document($provider, [['code' => 'a']])]);
        $applier = Harness::applier($planner);
        $this->expectException(ApplyInterruptedException::class);
        $applier->apply($set, $planner->prepare($set, Harness::platform()), Harness::context(), new PruneOptions(), null);
    }
}
