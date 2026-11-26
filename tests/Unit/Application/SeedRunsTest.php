<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Closure;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Application\ApplyOutcome;
use Nowo\SeedKitBundle\Application\Exporter;
use Nowo\SeedKitBundle\Application\RunView;
use Nowo\SeedKitBundle\Application\SchemaPublisher;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Application\SeedRuns;
use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun;
use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRunHandler;
use Nowo\SeedKitBundle\Domain\Audit\SeedAuditRecord;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ApplyInterruptedException;
use Nowo\SeedKitBundle\Domain\Exception\LeaseHeldException;
use Nowo\SeedKitBundle\Domain\Exception\PlanTooLargeException;
use Nowo\SeedKitBundle\Domain\Exception\PruneRefusedException;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunQueueInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Infrastructure\Run\GzipRunDocumentCodec;
use Nowo\SeedKitBundle\Testing\Support\InMemoryLeaseStore;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use Nowo\SeedKitBundle\Testing\Support\SequentialIdGenerator;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\CapturingWriter;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\HookedWriteRunner;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingAuditSink;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use Nowo\TransactionContract\ConcurrencyConflict;
use Nowo\TransactionContract\TransientFailureKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Throwable;

use function array_keys;
use function array_map;
use function count;
use function in_array;
use function json_encode;

use const DATE_ATOM;

/**
 * Stored UI runs and the asynchronous apply (contract 10.2–10.5, 11): upload → planned run with its document, view,
 * apply by compare-and-set claim, queue, resume after a partial apply (a batch never applied twice), Messenger apply
 * with the run's actor, discard, history, expiry; every apply / prune / discard audited.
 */
final class SeedRunsTest extends TestCase
{
    private InMemoryStore $store;

    private InMemoryRunStore $runs;

    private InMemoryLeaseStore $leases;

    private RecordingAuditSink $audit;

    private RecordingLogger $logger;

    private MockClock $clock;

    /** @var list<string> refused attributes, `SEED_X` or `SEED_X:actor` */
    private array $denied = [];

    /** @var list<array{string, string}> */
    public array $queued = [];

    /** @var (Closure(int): void)|null */
    private ?Closure $beforeWrite = null;

    /** Writes so far. */
    private int $calls = 0;

    /** Writes before the current hook. */
    private int $base = 0;

    protected function setUp(): void
    {
        $this->store  = new InMemoryStore();
        $this->runs   = new InMemoryRunStore();
        $this->leases = new InMemoryLeaseStore();
        $this->audit  = new RecordingAuditSink();
        $this->logger = new RecordingLogger();
        $this->clock  = new MockClock(new DateTimeImmutable('2026-11-24T10:00:00Z'));
    }

    /**
     * Gate callback.
     */
    public function grants(SeedAction $action, ActorRef $actor): bool
    {
        return !in_array($action->attribute(), $this->denied, true) && !in_array($action->attribute() . ':' . $actor->value, $this->denied, true);
    }

    private function settings(int $batchSize = 500, int $maxSync = 100, int $async = 100, float $ratio = 0.5): SeedKitSettings
    {
        return new SeedKitSettings(['platform' => false, 'organization' => true], $batchSize, 300, 200, 200, $ratio, 'seed-kit', 16, 10000, 65536, 256, 3600, $maxSync, $async);
    }

    private function service(?SeedKitSettings $settings = null, bool $queue = false, ?Throwable $queueFails = null): SeedRuns
    {
        $settings ??= $this->settings();
        $provider = new TagProvider($this->store);
        $planner  = Harness::planner([$provider], $settings);
        $test     = $this;
        $writer   = new HookedWriteRunner(static function (int $call) use ($test): void {
            $test->before($call);
        });
        $applier = Harness::applier($planner, $settings, $writer, $this->leases, $this->runs, $this->logger);
        $gate    = new class($test) implements SeedAccessGateInterface {
            public function __construct(private readonly SeedRunsTest $test)
            {
            }

            public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool
            {
                return $this->test->grants($action, $actor);
            }
        };
        $scopes = new class implements SeedScopeResolverInterface {
            public function resolve(SeedScope $requested, ActorRef $actor, Channel $channel): ?SeedScope
            {
                return $requested->id?->value === 'hidden' ? null : $requested;
            }
        };
        $operations = new SeedOperations(
            Harness::registry([$provider]),
            $planner,
            $applier,
            new Exporter(new CapturingWriter(), $settings, $this->clock, new PrefixSecretValidator()),
            new SchemaPublisher(),
            $scopes,
            $gate,
            $this->audit,
            $settings,
            null,
        );
        $queueService = !$queue ? null : new class($test, $queueFails) implements RunQueueInterface {
            public function __construct(private readonly SeedRunsTest $test, private readonly ?Throwable $fails)
            {
            }

            public function queue(RunId $run, PlanHash $planHash): void
            {
                if ($this->fails instanceof Throwable) {
                    throw $this->fails;
                }
                $this->test->queued[] = [$run->value, $planHash->value];
            }
        };

        return new SeedRuns($operations, $applier, $this->runs, new GzipRunDocumentCodec(), $writer, $this->audit, new SequentialIdGenerator(), $settings, $this->clock, $queueService, $this->logger);
    }

    /**
     * Write hook.
     */
    public function before(int $call): void
    {
        $this->calls = $call;
        if ($this->beforeWrite instanceof Closure) {
            ($this->beforeWrite)($call - $this->base);
        }
    }

    /**
     * Numbers the next writes from 1 (the hooks below count from the point they are set).
     */
    private function hook(?Closure $hook): void
    {
        $this->base        = $this->calls;
        $this->beforeWrite = $hook;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function set(array $items): DocumentSet
    {
        return Harness::set([Harness::document(new TagProvider($this->store), $items, 'platform')]);
    }

    private function tagCount(): int
    {
        return count($this->store->rows['demo.tags'] ?? []);
    }

    private function alice(): ActorRef
    {
        return new ActorRef('alice');
    }

    /**
     * @return list<string>
     */
    private function outcomes(): array
    {
        return array_map(static fn (SeedAuditRecord $r): string => $r->action . ':' . $r->outcome . ':' . $r->channel->value, $this->audit->records);
    }

    // ---------------------------------------------------------------- upload / apply

    #[Test]
    public function anUploadIsPlannedStoredAndAppliedOnce(): void
    {
        $runs = $this->service();
        $set  = $this->set([['slug' => 'news', 'label' => 'News'], ['slug' => 'blog', 'label' => 'Blog']]);
        $run  = $runs->upload($set, SeedScope::platform(), $this->alice(), false);

        self::assertSame(RunStatus::Planned, $run->status);
        self::assertSame(Channel::Http, $run->channel);
        self::assertSame(2, $run->changes());
        self::assertSame(2, $run->planned('create'));
        self::assertFalse($run->isMassPrune());
        self::assertSame('2026-11-24T11:00:00+00:00', $run->expiresAt?->format(DATE_ATOM));
        self::assertNotNull($this->runs->document($run->id));
        self::assertSame([], $this->store->rows, 'a dry run writes nothing');
        self::assertSame($run->id->value, $runs->upload($set, SeedScope::platform(), $this->alice(), false)->id->value, 'the same file reuses the planned run');
        self::assertCount(1, $this->runs->all());

        $view = $runs->view($run->id, SeedScope::platform(), $this->alice());
        self::assertTrue($view->owner);
        self::assertFalse($view->stale);
        self::assertSame($run->planHash->value, $view->plan?->hash->value);

        $outcome = $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(ApplyOutcome::APPLIED, $outcome->kind);
        self::assertSame(RunStatus::Applied, $outcome->run->status);
        self::assertSame(['create' => 2, 'update' => 0, 'delete' => 0], $outcome->result?->written);
        self::assertSame(2, $this->tagCount());
        self::assertNull($this->runs->document($run->id), 'the document is deleted when the run ends');
        self::assertSame(2, $outcome->run->planned('create'), 'the reviewed counts stay next to the written ones');
        self::assertSame(['apply:success:http'], $this->outcomes());

        $again = $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(ApplyOutcome::BUSY, $again->kind, 'a double click is idempotent');
        self::assertSame(1, $this->store->applyCalls);

        $view = $runs->view($run->id, SeedScope::platform(), $this->alice());
        self::assertNull($view->plan, 'no preview once the document is gone');
    }

    #[Test]
    public function aMassPruneNeedsItsConfirmationAndAnEmptyPruneIsRefused(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->store->rows['demo.tags']['["' . $slug . '"]'] = ['slug' => $slug, 'label' => $slug];
        }
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'a']]), SeedScope::platform(), $this->alice(), true);
        self::assertTrue($run->isMassPrune());
        self::assertSame(3, $run->planned('delete'));

        try {
            $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
            self::fail('mass prune without confirmation');
        } catch (PruneRefusedException $e) {
            self::assertSame(PruneRefusedException::MASS, $e->guard);
        }
        $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice(), true);
        self::assertCount(1, $this->store->rows['demo.tags']);
        self::assertSame(['prune:success:http'], $this->outcomes());

        $this->expectException(PruneRefusedException::class);
        $runs->upload($this->set([]), SeedScope::platform(), $this->alice(), true);
    }

    #[Test]
    public function applyIsBoundToTheActorThePlanAndALiveRun(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);

        $this->assertThrows(RunNotAvailableException::class, static fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), new ActorRef('bob')));
        $this->assertThrows(RunNotAvailableException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, Harness::org(), $this->alice()));
        $this->assertThrows(RunNotAvailableException::class, fn (): ApplyOutcome => $runs->apply(new RunId('01936f2a-0000-7000-8000-000000000099'), $run->planHash, SeedScope::platform(), $this->alice()));
        $this->assertThrows(StalePlanException::class, fn (): ApplyOutcome => $runs->apply($run->id, new PlanHash('sk1:' . str_repeat('0', 64)), SeedScope::platform(), $this->alice()));
        $this->assertThrows(ScopeNotFoundException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::parse('organization:hidden'), $this->alice()));
        $this->assertThrows(AccessDeniedException::class, static fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), ActorRef::system()));

        $runs->discard($run->id, SeedScope::platform(), $this->alice());
        self::assertSame(RunStatus::Discarded, $this->runs->find($run->id)?->status);
        self::assertNull($this->runs->document($run->id));
        self::assertSame(['discard:success:http'], $this->outcomes());
        $this->assertThrows(RunNotAvailableException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        $this->assertThrows(RunNotAvailableException::class, fn (): RunRecord => $runs->discard($run->id, SeedScope::platform(), $this->alice()));
    }

    #[Test]
    public function aChangeAfterTheReviewMakesTheRunStale(): void
    {
        $runs                                       = $this->service();
        $run                                        = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);
        $this->store->rows['demo.tags']['["news"]'] = ['slug' => 'news', 'label' => 'Edited meanwhile'];

        self::assertTrue($runs->view($run->id, SeedScope::platform(), $this->alice())->stale);
        $this->assertThrows(StalePlanException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        $failed = $this->runs->find($run->id);
        self::assertSame(RunStatus::Failed, $failed?->status);
        self::assertSame('SEED_005', $failed->errorCode);
        self::assertNull($this->runs->document($run->id));
        self::assertSame(['apply:SEED_005:http'], $this->outcomes());
    }

    #[Test]
    public function anotherViewerSeesMetadataOnlyAndOnlyWithApplyEverywhere(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);

        $view = $runs->view($run->id, SeedScope::platform(), new ActorRef('bob'));
        self::assertFalse($view->owner);
        self::assertNull($view->plan);
        self::assertSame($run->id->value, $runs->progress($run->id, SeedScope::platform(), new ActorRef('bob'))->id->value);
        self::assertCount(1, $runs->history(SeedScope::platform(), new ActorRef('bob'), 10));

        $this->denied = ['SEED_APPLY:bob'];
        $this->assertThrows(RunNotAvailableException::class, static fn (): RunView => $runs->view($run->id, SeedScope::platform(), new ActorRef('bob')));
        self::assertSame([], $runs->history(SeedScope::platform(), new ActorRef('bob'), 10), 'own runs only');
        $this->assertThrows(RunNotAvailableException::class, fn (): RunView => $runs->view($run->id, Harness::org(), $this->alice()));
    }

    #[Test]
    public function aBrokenStoredDocumentIsReportedNotThrown(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);
        $this->runs->create($run, 'not gzip');

        self::assertSame('SEED_008', $runs->view($run->id, SeedScope::platform(), $this->alice())->error);
    }

    #[Test]
    public function aPlanAboveTheSyncLimitIsRefusedWithoutMessengerAndQueuedWithIt(): void
    {
        $settings = $this->settings(500, 1, 1);
        $set      = $this->set([['slug' => 'news', 'label' => 'News'], ['slug' => 'blog', 'label' => 'Blog']]);
        $runs     = $this->service($settings);
        self::assertFalse($runs->isAsync());
        $run = $runs->upload($set, SeedScope::platform(), $this->alice(), false);
        try {
            $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
            self::fail('SEED_009');
        } catch (PlanTooLargeException $e) {
            self::assertSame([2, 1, 'SEED_009'], [$e->changes, $e->limit, $e->errorCode()]);
        }

        $runs = $this->service($settings, true);
        self::assertTrue($runs->isAsync());
        $outcome = $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(ApplyOutcome::QUEUED, $outcome->kind);
        self::assertSame(RunStatus::Queued, $outcome->run->status);
        self::assertSame([[$run->id->value, $run->planHash->value]], $this->queued, 'the message carries the run id and the plan hash only');
        self::assertSame(['apply:queued:http'], $this->outcomes());
        self::assertSame(ApplyOutcome::BUSY, $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice())->kind);

        $applied = $runs->applyQueued($run->id, $run->planHash);
        self::assertSame(ApplyOutcome::APPLIED, $applied?->kind);
        self::assertSame(Channel::Messenger, $this->audit->records[1]->channel);
        self::assertCount(2, $this->store->rows['demo.tags']);
        self::assertNull($runs->applyQueued($run->id, $run->planHash), 'a redelivery after the run ended is a no-op');
        self::assertNull($runs->applyQueued(new RunId('01936f2a-0000-7000-8000-000000000099'), $run->planHash));
    }

    #[Test]
    public function aFailedHandOverPutsTheRunBack(): void
    {
        $settings = $this->settings(500, 1, 1);
        $runs     = $this->service($settings, true, new RuntimeException('bus down'));
        $run      = $runs->upload($this->set([['slug' => 'news', 'label' => 'News'], ['slug' => 'blog', 'label' => 'Blog']]), SeedScope::platform(), $this->alice(), false);

        $this->assertThrows(RuntimeException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(RunStatus::Planned, $this->runs->find($run->id)?->status);
        self::assertSame(1, $this->logger->count('error'));

        $runs         = $this->service($settings, true);
        $this->denied = ['SEED_APPLY:alice'];
        $this->assertThrows(AccessDeniedException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(['apply:ACCESS_002:http'], $this->outcomes());
        self::assertSame([], $this->queued);
    }

    #[Test]
    public function theMessengerApplyRunsAsTheRunActorAndFailsForGood(): void
    {
        $settings = $this->settings(500, 1, 1);
        $runs     = $this->service($settings, true);
        $set      = $this->set([['slug' => 'news', 'label' => 'News'], ['slug' => 'blog', 'label' => 'Blog']]);

        $run = $runs->upload($set, SeedScope::platform(), $this->alice(), false);
        $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertNull($runs->applyQueued($run->id, new PlanHash('sk1:' . str_repeat('0', 64))), 'not the run plan');
        $this->denied = ['SEED_APPLY:alice'];
        $this->assertThrows(AccessDeniedException::class, static fn (): ?\Nowo\SeedKitBundle\Application\ApplyOutcome => $runs->applyQueued($run->id, $run->planHash));
        self::assertSame(RunStatus::Failed, $this->runs->find($run->id)?->status, 'refused for good');
        self::assertSame('apply:ACCESS_002:messenger', $this->outcomes()[1]);

        $this->denied         = [];
        $this->audit->records = [];
        $system               = $this->runs->find($run->id);
        self::assertNotNull($system);
        $other = $this->rerun($system, RunStatus::Queued, ActorRef::system(), 2);
        $this->assertThrows(AccessDeniedException::class, static fn (): ?\Nowo\SeedKitBundle\Application\ApplyOutcome => $runs->applyQueued($other->id, $other->planHash));
        self::assertSame(RunStatus::Failed, $this->runs->find($other->id)?->status, 'the system actor is refused on the Messenger channel');
        self::assertSame(['apply:ACCESS_002:messenger'], $this->outcomes());

        $forgotten = $this->rerun($system, RunStatus::Queued, null, 3);
        $this->assertThrows(RunNotAvailableException::class, static fn (): ?\Nowo\SeedKitBundle\Application\ApplyOutcome => $runs->applyQueued($forgotten->id, $forgotten->planHash));
        self::assertSame(RunStatus::Failed, $this->runs->find($forgotten->id)?->status);
    }

    #[Test]
    public function aPartialRunResumesAfterItsLastCommittedBatch(): void
    {
        $runs = $this->service($this->settings(1));
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'A'], ['slug' => 'b', 'label' => 'B'], ['slug' => 'c', 'label' => 'C']]), SeedScope::platform(), $this->alice(), false);
        // Writes: 1 claim, 2 batch 1, 3 batch 2 (fails), 4 finish.
        $this->hook(static function (int $call): void {
            if ($call === 3) {
                throw new RuntimeException('provider down');
            }
        });
        $this->assertThrows(ApplyInterruptedException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        $partial = $this->runs->find($run->id);
        self::assertSame(RunStatus::PartiallyApplied, $partial?->status);
        self::assertSame(1, $partial->batchesDone);
        self::assertSame('SEED_010', $partial->errorCode);
        self::assertNotNull($this->runs->document($run->id), 'kept for the resume');
        self::assertCount(1, $this->store->rows['demo.tags']);
        self::assertFalse($runs->view($run->id, SeedScope::platform(), $this->alice())->stale, 'a resumed run is not compared with its first plan');

        $this->beforeWrite = null;
        $outcome           = $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(RunStatus::Applied, $outcome->run->status);
        self::assertSame(3, $outcome->run->batchesDone);
        self::assertSame(['create' => 3, 'update' => 0, 'delete' => 0], $outcome->result?->written);
        self::assertCount(3, $this->store->rows['demo.tags']);
        self::assertSame(3, $this->store->applyCalls, 'every batch applied exactly once (batch 2 was rolled back before its write)');
        self::assertSame(['apply:SEED_010:http', 'apply:success:http'], $this->outcomes());
    }

    #[Test]
    public function aCrashedWorkersRunIsTakenOverAfterTheLease(): void
    {
        $settings = $this->settings(1, 1, 1);
        $runs     = $this->service($settings, true);
        $run      = $runs->upload($this->set([['slug' => 'a', 'label' => 'A'], ['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);
        $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        $queued = $this->runs->find($run->id);
        self::assertNotNull($queued);
        self::assertTrue($this->runs->transition($run->id, $queued->version, [RunStatus::Queued], RunStatus::Running), 'a worker claimed it and died');
        $this->runs->touch($run->id, new DateTimeImmutable('2026-11-24T09:59:00Z'));

        self::assertSame(ApplyOutcome::BUSY, $runs->applyQueued($run->id, $run->planHash)?->kind, 'not before the lease');
        $this->runs->touch($run->id, new DateTimeImmutable('2026-11-24T09:50:00Z'));
        self::assertSame(ApplyOutcome::APPLIED, $runs->applyQueued($run->id, $run->planHash)?->kind);
        self::assertCount(2, $this->store->rows['demo.tags']);
    }

    #[Test]
    public function nothingWrittenPutsTheClaimBack(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);

        $this->leases->acquire(new ProviderName('demo.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'), 120);
        $this->assertThrows(LeaseHeldException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        $back = $this->runs->find($run->id);
        self::assertSame(RunStatus::Planned, $back?->status);
        self::assertSame('CONFLICT_001', $back->errorCode);
        $this->leases->release(new ProviderName('demo.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'));

        // A lock conflict that survived the replays before the first batch: planned again (applier).
        $this->hook(static function (int $call): void {
            if ($call === 2) {
                throw new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 2, new RuntimeException('deadlock'));
            }
        });
        $this->assertThrows(ApplyInterruptedException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(RunStatus::Planned, $this->runs->find($run->id)?->status);

        // A failure in the re-plan (nothing written): back to planned, audited as an error.
        $this->beforeWrite     = null;
        $this->store->failPlan = true;
        $this->assertThrows(RuntimeException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(RunStatus::Planned, $this->runs->find($run->id)?->status);
        self::assertSame(['apply:CONFLICT_001:http', 'apply:CONFLICT_001:http', 'apply:error:http'], $this->outcomes());
    }

    #[Test]
    public function aLostClaimIsBusy(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);
        // Another request claims it between our read and our claim.
        $this->hook(function (int $call) use ($run): void {
            if ($call === 1) {
                $this->beforeWrite = null;
                $this->runs->transition($run->id, 1, [RunStatus::Planned], RunStatus::Running);
            }
        });

        self::assertSame(ApplyOutcome::BUSY, $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice())->kind);
        self::assertSame(0, $this->store->applyCalls);
    }

    #[Test]
    public function documentsExpireBestEffort(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'news', 'label' => 'News']]), SeedScope::platform(), $this->alice(), false);

        self::assertSame(0, $runs->expire());
        self::assertSame(1, $runs->expire(new DateTimeImmutable('2026-11-24T12:00:00Z')));
        self::assertSame(RunStatus::Expired, $this->runs->find($run->id)?->status);

        $this->hook(static function (): never {
            throw new RuntimeException('down');
        });
        self::assertSame(0, $runs->expire());
        self::assertSame(1, $this->logger->count('warning'));
    }

    #[Test]
    public function aResumedPruneSkipsItsCommittedDeleteBatches(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $slug) {
            $this->store->rows['demo.tags']['["' . $slug . '"]'] = ['slug' => $slug, 'label' => $slug];
        }
        $runs = $this->service($this->settings(1, 100, 100, 1.0));
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'a']]), SeedScope::platform(), $this->alice(), true);
        // Writes: 1 claim, 2 delete b, 3 delete c (fails), 4 finish.
        $this->hook(static function (int $call): void {
            if ($call === 3) {
                throw new RuntimeException('down');
            }
        });
        $this->assertThrows(ApplyInterruptedException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(1, $this->runs->find($run->id)?->batchesDone);
        self::assertCount(3, $this->store->rows['demo.tags']);

        $this->hook(null);
        $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(['["a"]'], array_keys($this->store->rows['demo.tags']));
        self::assertSame(3, $this->runs->find($run->id)?->batchesDone);
    }

    #[Test]
    public function edgeCasesOfTheStoredRun(): void
    {
        $settings = $this->settings(500, 1, 1);
        $runs     = $this->service($settings, true);
        $run      = $runs->upload($this->set([['slug' => 'a', 'label' => 'A'], ['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);

        // Another request queues it between our read and our claim.
        $this->hook(function (int $call) use ($run): void {
            if ($call === 1) {
                $this->hook(null);
                $this->runs->transition($run->id, 1, [RunStatus::Planned], RunStatus::Queued);
            }
        });
        self::assertSame(ApplyOutcome::BUSY, $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice())->kind);
        self::assertSame([], $this->queued);

        // A run without a stored layout (0.1 row) or without its document cannot be applied.
        $legacy = new RunRecord(new RunId('01936f2a-0000-7000-8000-000000000201'), SeedScope::platform(), $this->alice(), Channel::Http, RunStatus::Planned, ['demo.tags' => 1], false, $run->documentHash, $run->planHash, [], 0, [], null, $run->createdAt, null, null, 1, $run->expiresAt, true);
        $this->runs->create($legacy, (new GzipRunDocumentCodec())->encode($this->set([['slug' => 'a', 'label' => 'A']])));
        $runs = $this->service();
        $this->assertThrows(RunNotAvailableException::class, fn (): ApplyOutcome => $runs->apply($legacy->id, $legacy->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(RunStatus::Planned, $this->runs->find($legacy->id)?->status, 'released');

        $empty = new RunRecord(new RunId('01936f2a-0000-7000-8000-000000000202'), SeedScope::platform(), $this->alice(), Channel::Http, RunStatus::Planned, ['demo.tags' => 1], false, $run->documentHash, $run->planHash, [], 0, [], null, $run->createdAt, null, $run->manifest, 1, $run->expiresAt, false);
        $this->runs->create($empty);
        $this->assertThrows(RunNotAvailableException::class, fn (): ApplyOutcome => $runs->apply($empty->id, $empty->planHash, SeedScope::platform(), $this->alice()));

        // A release that fails is logged (the claim is taken over after the lease anyway).
        $this->leases->acquire(new ProviderName('demo.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'), 120);
        $fresh = $runs->upload($this->set([['slug' => 'c', 'label' => 'C']]), SeedScope::platform(), $this->alice(), false);
        $this->hook(static function (int $call): void {
            if ($call === 2) {
                throw new RuntimeException('down');
            }
        });
        $this->assertThrows(LeaseHeldException::class, fn (): ApplyOutcome => $runs->apply($fresh->id, $fresh->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(1, $this->logger->count('warning'));
    }

    #[Test]
    public function theHandlerMapsOutcomesToTheRetryPolicy(): void
    {
        $settings = $this->settings(500, 1, 1);
        $runs     = $this->service($settings, true);
        $handler  = new ApplySeedRunHandler($runs, $this->logger);
        $run      = $runs->upload($this->set([['slug' => 'a', 'label' => 'A'], ['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);
        $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());

        $this->assertThrows(UnrecoverableMessageHandlingException::class, static fn () => $handler(new ApplySeedRun('nope', $run->planHash->value)));

        $this->leases->acquire(new ProviderName('demo.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'), 120);
        $this->assertThrows(RecoverableMessageHandlingException::class, static fn () => $handler(new ApplySeedRun($run->id->value, $run->planHash->value)));
        self::assertSame(RunStatus::Queued, $this->runs->find($run->id)?->status, 'back in the queue');
        $this->leases->release(new ProviderName('demo.tags'), SeedScope::platform(), new RunId('01936f2a-0000-7000-8000-000000000077'));

        // Writes: 1 claim, 2 batch (lock conflict before the first batch): back in the queue, and the transport's
        // retry really applies it (ZCE-L2).
        $this->hook(static function (int $call): void {
            if ($call === 2) {
                throw new ConcurrencyConflict(3, TransientFailureKind::Deadlock, 2, new RuntimeException('deadlock ana@example.com'));
            }
        });
        try {
            $handler(new ApplySeedRun($run->id->value, $run->planHash->value));
            self::fail('retryable');
        } catch (RecoverableMessageHandlingException $e) {
            self::assertNull($e->getPrevious(), 'no driver message reaches the failure transport (ZCE-L4)');
            self::assertStringNotContainsString('ana@', $e->getMessage());
        }
        self::assertSame(RunStatus::Queued, $this->runs->find($run->id)?->status, 'still queued: the retry applies it');
        $this->hook(null);
        $handler(new ApplySeedRun($run->id->value, $run->planHash->value));
        self::assertSame(RunStatus::Applied, $this->runs->find($run->id)?->status);
        self::assertSame(2, $this->tagCount());

        $next = $runs->upload($this->set([['slug' => 'c', 'label' => 'C'], ['slug' => 'd', 'label' => 'D']]), SeedScope::platform(), $this->alice(), false);
        $runs->apply($next->id, $next->planHash, SeedScope::platform(), $this->alice());
        $this->hook(static function (int $call): void {
            if ($call === 2) {
                throw new RuntimeException('Duplicate entry ana@example.com');
            }
        });
        try {
            $handler(new ApplySeedRun($next->id->value, $next->planHash->value));
            self::fail('final');
        } catch (UnrecoverableMessageHandlingException $e) {
            self::assertNull($e->getPrevious(), 'no provider message reaches the failure transport (ZCE-L4)');
            self::assertStringNotContainsString('ana@', $e->getMessage());
        }
        self::assertSame(RunStatus::Failed, $this->runs->find($next->id)?->status);
        foreach ($this->logger->records as [, $message, $context]) {
            self::assertStringNotContainsString('ana@', $message . json_encode($context));
        }
        $this->hook(static function (): never {
            throw new RuntimeException('Duplicate entry ana@example.com');
        });
        try {
            $handler(new ApplySeedRun($next->id->value, $next->planHash->value));
        } catch (RuntimeException $e) {
            self::fail('an ended run is a no-op: ' . $e::class);
        }

        $other = $this->rerun($run, RunStatus::Queued, ActorRef::system(), 4);
        $this->hook(null);
        $this->assertThrows(UnrecoverableMessageHandlingException::class, static fn () => $handler(new ApplySeedRun($other->id->value, $other->planHash->value)));

        // An unexpected failure (a driver error on the claim): retried by the transport, class only.
        $last = $runs->upload($this->set([['slug' => 'e', 'label' => 'E'], ['slug' => 'f', 'label' => 'F']]), SeedScope::platform(), $this->alice(), false);
        $runs->apply($last->id, $last->planHash, SeedScope::platform(), $this->alice());
        $this->hook(static function (): never {
            throw new RuntimeException('SQLSTATE ana@example.com');
        });
        try {
            $handler(new ApplySeedRun($last->id->value, $last->planHash->value));
            self::fail('unexpected');
        } catch (RuntimeException $e) {
            self::assertNull($e->getPrevious());
            self::assertSame('Seed run failed unexpectedly (RuntimeException).', $e->getMessage());
        }
        self::assertSame(RunStatus::Queued, $this->runs->find($last->id)?->status);
    }

    #[Test]
    public function aStalledWebRunIsTakenOverOrDiscarded(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'A'], ['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);
        // The request that applied it died (timeout, OOM): the row stays `running` (ZCE-M1).
        self::assertTrue($this->runs->transition($run->id, $run->version, [RunStatus::Planned], RunStatus::Running));
        $this->runs->touch($run->id, new DateTimeImmutable('2026-11-24T09:58:00Z'));

        self::assertSame(ApplyOutcome::BUSY, $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice())->kind, 'not before the lease');
        self::assertFalse($runs->view($run->id, SeedScope::platform(), $this->alice())->stalled);
        $this->assertThrows(RunNotAvailableException::class, static fn (): RunRecord => $runs->discard($run->id, SeedScope::platform(), new ActorRef('bob')));

        $this->runs->touch($run->id, new DateTimeImmutable('2026-11-24T09:50:00Z'));
        $view = $runs->view($run->id, SeedScope::platform(), $this->alice());
        self::assertTrue($view->stalled);
        self::assertNotNull($view->plan, 'its actor sees the diff to take it over');
        self::assertTrue($runs->view($run->id, SeedScope::platform(), new ActorRef('bob'))->stalled);

        $outcome = $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(ApplyOutcome::APPLIED, $outcome->kind, 'taken over by its actor');
        self::assertSame(2, $this->tagCount());

        // An admin of the kind (apply on every provider) discards another actor's stalled run.
        $other = $runs->upload($this->set([['slug' => 'c', 'label' => 'C']]), SeedScope::platform(), $this->alice(), false);
        self::assertTrue($this->runs->transition($other->id, $other->version, [RunStatus::Planned], RunStatus::Running));
        $this->runs->touch($other->id, new DateTimeImmutable('2026-11-24T09:50:00Z'));
        self::assertSame(RunStatus::Discarded, $runs->discard($other->id, SeedScope::platform(), new ActorRef('bob'))->status);
        self::assertNull($this->runs->document($other->id));
    }

    #[Test]
    public function aDocumentPastItsTtlIsNeverUsed(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'A']]), SeedScope::platform(), $this->alice(), false);
        $this->clock->modify('+2 hours');

        $view = $runs->view($run->id, SeedScope::platform(), $this->alice());
        self::assertSame('SEED_005', $view->error, 'upload again');
        self::assertNull($view->plan);
        self::assertSame(RunStatus::Expired, $this->runs->find($run->id)?->status, 'ended on the spot');

        $late = $runs->upload($this->set([['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);
        $this->clock->modify('+2 hours');
        $this->assertThrows(StalePlanException::class, fn (): ApplyOutcome => $runs->apply($late->id, $late->planHash, SeedScope::platform(), $this->alice()));
        self::assertNull($this->runs->document($late->id));
        self::assertSame([], $this->store->rows);

        // A stalled run past its TTL plus the lease is ended by the sweep (the history page runs it too).
        $stuck = $runs->upload($this->set([['slug' => 'c', 'label' => 'C']]), SeedScope::platform(), $this->alice(), false);
        self::assertTrue($this->runs->transition($stuck->id, $stuck->version, [RunStatus::Planned], RunStatus::Running));
        $this->runs->touch($stuck->id, DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->clock->modify('+2 hours');
        $runs->history(SeedScope::platform(), $this->alice(), 10);
        self::assertSame(RunStatus::Expired, $this->runs->find($stuck->id)?->status);
        self::assertNull($this->runs->document($stuck->id));
    }

    #[Test]
    public function aClaimTakenOverMeanwhileStopsAtItsNextFencedWrite(): void
    {
        $runs = $this->service();
        $run  = $runs->upload($this->set([['slug' => 'a', 'label' => 'A']]), SeedScope::platform(), $this->alice(), false);
        $take = function () use ($run): void {
            $current = $this->runs->find($run->id);
            self::assertNotNull($current);
            // Another claimer takes the run over (its version moves on).
            self::assertTrue($this->runs->transition($run->id, $current->version, [RunStatus::Running], RunStatus::Running, null, null, new DateTimeImmutable('2030-01-01T00:00:00Z')));
        };

        // During the re-plan: the heartbeat before the batches finds the claim lost.
        $this->store->onPlan = static function () use (&$take): void {
            if ($take !== null) {
                $take();
                $take = null;
            }
        };
        $this->assertThrows(ApplyInterruptedException::class, fn (): ApplyOutcome => $runs->apply($run->id, $run->planHash, SeedScope::platform(), $this->alice()));
        self::assertSame(RunStatus::Running, $this->runs->find($run->id)?->status, 'the other claimer owns it');
        self::assertSame([], $this->store->rows);
        $this->store->onPlan = null;

        // After the last batch: the fenced finish changes nothing, the new claimer ends the run.
        $fresh = $runs->upload($this->set([['slug' => 'b', 'label' => 'B']]), SeedScope::platform(), $this->alice(), false);
        $this->hook(function (int $call) use ($fresh): void {
            if ($call === 3) {
                $current = $this->runs->find($fresh->id);
                self::assertNotNull($current);
                $this->runs->transition($fresh->id, $current->version, [RunStatus::Running], RunStatus::Running, null, null, new DateTimeImmutable('2030-01-01T00:00:00Z'));
            }
        });
        $outcome = $runs->apply($fresh->id, $fresh->planHash, SeedScope::platform(), $this->alice());
        self::assertSame(RunStatus::Running, $outcome->run->status, 'not marked applied by the old claim');
        self::assertSame(1, $this->logger->count('warning'));
    }

    /**
     * A copy of a run under another id, status and actor (stored with a document).
     */
    /**
     * @param list<array<string, mixed>> $items
     */
    private function rerun(RunRecord $run, RunStatus $status, ?ActorRef $actor, int $number, array $items = [['slug' => 'news', 'label' => 'News'], ['slug' => 'blog', 'label' => 'Blog']]): RunRecord
    {
        $copy = new RunRecord(new RunId('01936f2a-0000-7000-8000-00000000010' . $number), $run->scope, $actor, $run->channel, $status, $run->providers, $run->prune, $run->documentHash, $run->planHash, $run->batchDigests, 0, $run->counts, null, $run->createdAt, null, $run->manifest, 1, new DateTimeImmutable('2026-11-24T11:00:00Z'), true);
        $this->runs->create($copy, (new GzipRunDocumentCodec())->encode($this->set($items)));

        return $copy;
    }

    /**
     * @param class-string<Throwable> $class
     * @param Closure(): mixed $call
     */
    private function assertThrows(string $class, Closure $call): void
    {
        try {
            $call();
        } catch (Throwable $e) {
            self::assertInstanceOf($class, $e, $e::class . ': ' . $e->getMessage());

            return;
        }
        self::fail('Expected ' . $class);
    }
}
