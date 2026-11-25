<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Closure;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Application\Exporter;
use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SchemaPublisher;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Event\SeedExportedEvent;
use Nowo\SeedKitBundle\Domain\Event\SeedRunPlannedEvent;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Exception\ScopeNotFoundException;
use Nowo\SeedKitBundle\Domain\Exception\StalePlanException;
use Nowo\SeedKitBundle\Domain\Exception\UnknownProviderException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\FieldValueView;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\CapturingWriter;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingAuditSink;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingDispatcher;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use function in_array;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * The application facade (contract 12): scope resolution, access per action, masking, events and audit.
 */
final class SeedOperationsTest extends TestCase
{
    private InMemoryStore $store;

    private RecordingDispatcher $events;

    private RecordingAuditSink $audit;

    private RecordingLogger $logger;

    private ScriptedProvider $items;

    /** @var list<string> attributes refused, as `SEED_X:provider` or `SEED_X` */
    private array $denied = [];

    /** @var (Closure(SeedScope): ?SeedScope)|null */
    private ?Closure $resolve = null;

    protected function setUp(): void
    {
        $this->store   = new InMemoryStore();
        $this->events  = new RecordingDispatcher();
        $this->audit   = new RecordingAuditSink();
        $this->logger  = new RecordingLogger();
        $this->denied  = [];
        $this->resolve = null;
    }

    private function operations(bool $withLoggerAndEvents = true): SeedOperations
    {
        $tags     = new TagProvider($this->store);
        $articles = new ArticleProvider($this->store);
        $items    = new ScriptedProvider();
        $items->seed('a', ['label' => 'A']);
        $this->items = $items;
        $providers   = [$tags, $articles, $items];
        $settings    = Harness::settings();
        $planner     = Harness::planner($providers, $settings);
        $test        = $this;
        $gate        = new class($test) implements SeedAccessGateInterface {
            public function __construct(private readonly SeedOperationsTest $test)
            {
            }

            public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool
            {
                return $this->test->grants($action, $provider);
            }
        };
        $scopes = new class($test) implements SeedScopeResolverInterface {
            public function __construct(private readonly SeedOperationsTest $test)
            {
            }

            public function resolve(SeedScope $requested, ActorRef $actor, Channel $channel): ?SeedScope
            {
                return $this->test->resolves($requested);
            }
        };

        return new SeedOperations(
            Harness::registry($providers),
            $planner,
            Harness::applier($planner, $settings),
            new Exporter(new CapturingWriter(), $settings, new MockClock(new DateTimeImmutable('2026-11-24')), new PrefixSecretValidator()),
            new SchemaPublisher(),
            $scopes,
            $gate,
            $this->audit,
            $settings,
            $withLoggerAndEvents ? $this->logger : null,
            $withLoggerAndEvents ? $this->events : null,
        );
    }

    /**
     * Gate callback.
     */
    public function grants(SeedAction $action, ProviderName $provider): bool
    {
        return !in_array($action->attribute(), $this->denied, true) && !in_array($action->attribute() . ':' . $provider->value, $this->denied, true);
    }

    /**
     * Resolver callback.
     */
    public function resolves(SeedScope $requested): ?SeedScope
    {
        return $this->resolve instanceof Closure ? ($this->resolve)($requested) : $requested;
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    private function tagsSet(): DocumentSet
    {
        return Harness::set([Harness::document(new TagProvider($this->store), [['slug' => 'news', 'label' => 'News']], 'organization')]);
    }

    // ---------------------------------------------------------------- providers / schema

    /**
     * @param list<SeedProviderInterface> $providers
     *
     * @return list<string>
     */
    private function names(array $providers): array
    {
        return array_map(static fn (SeedProviderInterface $p): string => $p->name()->value, $providers);
    }

    #[Test]
    public function listsProvidersAndPublishesSchemas(): void
    {
        $operations = $this->operations();
        self::assertSame(['demo.tags', 'demo.articles', 'demo.items'], $this->names($operations->providers()));
        self::assertSame(['demo.tags', 'demo.items'], $this->names($operations->providers(SeedScope::platform())));
        self::assertSame('urn:nowo-seed:demo.tags:1', $operations->schema(new ProviderName('demo.tags'))['$id']);

        $this->expectException(UnknownProviderException::class);
        $operations->schema(new ProviderName('demo.nope'));
    }

    // ---------------------------------------------------------------- scope

    #[Test]
    public function resolvesAScope(): void
    {
        $scope = $this->operations()->resolveScope(Harness::org(), new ActorRef('alice'), Channel::Http);
        self::assertSame('organization:org-1', $scope->key());
        self::assertSame('platform', $this->operations()->resolveScope(SeedScope::platform(), ActorRef::system(), Channel::Console)->key());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unresolvableScopes(): iterable
    {
        yield 'unknown kind' => ['project:p1'];
        yield 'id required' => ['organization'];
        yield 'id forbidden' => ['platform:x'];
    }

    #[Test]
    #[DataProvider('unresolvableScopes')]
    public function refusesScopesOutsideTheConfiguration(string $scope): void
    {
        $this->expectException(ScopeNotFoundException::class);
        $this->operations()->resolveScope(SeedScope::parse($scope), new ActorRef('alice'), Channel::Http);
    }

    #[Test]
    public function refusesAScopeTheResolverDoesNotShow(): void
    {
        $this->resolve = static fn (): ?SeedScope => null;
        $this->expectException(ScopeNotFoundException::class);
        $this->operations()->resolveScope(Harness::org(), new ActorRef('alice'), Channel::Http);
    }

    #[Test]
    public function refusesAScopeTheResolverSwaps(): void
    {
        $this->resolve = static fn (): SeedScope => Harness::org('org-2');
        $this->expectException(ScopeNotFoundException::class);
        $this->operations()->resolveScope(Harness::org(), new ActorRef('alice'), Channel::Http);
    }

    /**
     * @return iterable<string, array{Channel}>
     */
    public static function nonConsoleChannels(): iterable
    {
        yield 'http' => [Channel::Http];
        yield 'messenger' => [Channel::Messenger];
    }

    #[Test]
    #[DataProvider('nonConsoleChannels')]
    public function theSystemActorIsConsoleOnly(Channel $channel): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->operations()->resolveScope(SeedScope::platform(), ActorRef::system(), $channel);
    }

    // ---------------------------------------------------------------- export

    #[Test]
    public function anEmptyExportIsLoggedNotAudited(): void
    {
        $rows = $this->operations()->export(new ProviderName('demo.tags'), Harness::org(), ExportMode::Empty, DocumentFormat::Yaml, new ActorRef('alice'), Channel::Http, $this->stream());
        self::assertSame(1, $rows);
        self::assertSame([], $this->audit->records);
        self::assertSame([['info', 'Seed export of {provider} in {scope} ({mode}, {rows} rows).', ['provider' => 'demo.tags', 'scope' => 'organization:org-1', 'mode' => 'empty', 'rows' => 1]]], $this->logger->records);
        $event = $this->events->events[0];
        self::assertInstanceOf(SeedExportedEvent::class, $event);
        self::assertSame([ExportMode::Empty, 1, 'demo.tags'], [$event->mode, $event->rows, $event->provider->value]);
    }

    #[Test]
    public function aSensitiveCurrentExportIsAudited(): void
    {
        $this->store->rows['demo.articles']['["a"]'] = ['code' => 'a', 'title' => 'A', 'owner' => 'bob'];
        $rows                                        = $this->operations()->export(new ProviderName('demo.articles'), Harness::org(), ExportMode::Current, DocumentFormat::Json, new ActorRef('alice'), Channel::Http, $this->stream());
        self::assertSame(1, $rows);
        self::assertCount(1, $this->audit->records);
        self::assertSame(['action' => 'export', 'actor' => 'alice', 'scope' => 'organization:org-1', 'providers' => ['demo.articles'], 'counts' => ['rows' => 1], 'channel' => 'http', 'outcome' => 'success', 'document_hash' => null, 'plan_hash' => null, 'run_id' => null], $this->audit->records[0]->toArray());
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function aNonSensitiveCurrentExportIsLogged(): void
    {
        $operations = $this->operations(false);
        self::assertSame(1, $operations->export(new ProviderName('demo.items'), Harness::org(), ExportMode::Current, DocumentFormat::Json, new ActorRef('alice'), Channel::Http, $this->stream()));
        self::assertSame([], $this->audit->records);
        self::assertSame([], $this->events->events, 'no dispatcher');
    }

    #[Test]
    public function exportNeedsTheExportPermission(): void
    {
        $this->denied = ['SEED_EXPORT'];
        $this->expectException(AccessDeniedException::class);
        $this->operations()->export(new ProviderName('demo.tags'), Harness::org(), ExportMode::Empty, DocumentFormat::Yaml, new ActorRef('alice'), Channel::Http, $this->stream());
    }

    #[Test]
    public function exportNeedsTheProviderInTheKind(): void
    {
        $this->expectException(UnknownProviderException::class);
        $this->operations()->export(new ProviderName('demo.articles'), SeedScope::platform(), ExportMode::Empty, DocumentFormat::Yaml, new ActorRef('alice'), Channel::Http, $this->stream());
    }

    // ---------------------------------------------------------------- diff

    #[Test]
    public function diffPlansAndDispatches(): void
    {
        $this->store->rows['demo.tags']['["news"]'] = ['slug' => 'news', 'label' => 'Old'];
        $plan                                       = $this->operations()->diff($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions());
        self::assertSame(1, $plan->count(ChangeOp::Update));
        self::assertSame(FieldValueView::VALUE, $plan->documents[0]->preview[0]->fields[0]->before->kind);
        $event = $this->events->events[0];
        self::assertInstanceOf(SeedRunPlannedEvent::class, $event);
        self::assertSame([['demo.tags'], 1], [$event->providers, $event->changes]);
        self::assertSame(0, $this->store->applyCalls);
        self::assertSame([], $this->audit->records, 'a dry run is not audited');
    }

    #[Test]
    public function diffMasksCurrentValuesWithoutExportPermission(): void
    {
        $this->store->rows['demo.tags']['["news"]'] = ['slug' => 'news', 'label' => 'Old'];
        $this->denied                               = ['SEED_EXPORT:demo.tags'];
        $plan                                       = $this->operations(false)->diff($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions());
        $field                                      = $plan->documents[0]->preview[0]->fields[0];
        self::assertSame([FieldValueView::HIDDEN, FieldValueView::VALUE], [$field->before->kind, $field->after->kind]);
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function applyDenials(): iterable
    {
        yield 'apply' => [['SEED_APPLY'], false];
        yield 'apply provider' => [['SEED_APPLY:demo.tags'], false];
        yield 'prune' => [['SEED_PRUNE'], true];
    }

    /**
     * @param list<string> $denied
     */
    #[Test]
    #[DataProvider('applyDenials')]
    public function diffAndApplyNeedTheirPermissions(array $denied, bool $prune): void
    {
        $this->denied = $denied;
        try {
            $this->operations()->diff($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions($prune));
            self::fail('expected');
        } catch (AccessDeniedException) {
        }
        try {
            $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions($prune));
            self::fail('expected');
        } catch (AccessDeniedException) {
        }
        self::assertCount(1, $this->audit->records, 'a refused apply is audited');
        self::assertSame('ACCESS_002', $this->audit->records[0]->outcome);
        self::assertSame(['demo.tags'], $this->audit->records[0]->providers);
    }

    #[Test]
    public function pruneWithoutPrunePermissionIsFineWhenNotPruning(): void
    {
        $this->denied = ['SEED_PRUNE'];
        $result       = $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions());
        self::assertSame(1, $result->batches);
    }

    // ---------------------------------------------------------------- apply

    #[Test]
    public function applyIsAuditedOnSuccess(): void
    {
        $this->store->rows['demo.tags']['["old"]'] = ['slug' => 'old', 'label' => 'Old'];
        $result                                    = $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Console, new PruneOptions(true, true));
        self::assertNotNull($result->runId);
        self::assertCount(1, $this->audit->records);
        self::assertSame([
            'action'        => 'prune',
            'actor'         => 'alice',
            'scope'         => 'organization:org-1',
            'providers'     => ['demo.tags'],
            'counts'        => ['create' => 1, 'update' => 0, 'delete' => 1],
            'channel'       => 'console',
            'outcome'       => 'success',
            'document_hash' => $this->tagsSet()->hash->value,
            'plan_hash'     => $result->plan->hash->value,
            'run_id'        => $result->runId->value,
        ], $this->audit->records[0]->toArray());
    }

    #[Test]
    public function anApplyWithoutChangeIsAuditedWithoutRun(): void
    {
        $this->store->rows['demo.tags']['["news"]'] = ['slug' => 'news', 'label' => 'News'];
        $result                                     = $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Console, new PruneOptions());
        self::assertNull($result->runId);
        self::assertSame('apply', $this->audit->records[0]->action);
        self::assertNull($this->audit->records[0]->runId);
    }

    #[Test]
    public function applyIsAuditedOnFailure(): void
    {
        $expected = new PlanHash('sk1:' . str_repeat('9', 64));
        try {
            $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions(), $expected);
            self::fail('expected');
        } catch (StalePlanException) {
        }
        self::assertCount(1, $this->audit->records);
        $record = $this->audit->records[0]->toArray();
        self::assertSame(['apply', 'SEED_005', [], $expected->value, null], [$record['action'], $record['outcome'], $record['counts'], $record['plan_hash'], $record['run_id']]);
        self::assertSame($this->tagsSet()->hash->value, $record['document_hash']);
        self::assertSame(0, $this->store->applyCalls);
    }

    #[Test]
    public function aHostFailureBeforeTheRunIsAuditedWithoutItsMessage(): void
    {
        $operations               = $this->operations();
        $this->items->onPlanBatch = static function (): never {
            throw new RuntimeException('Duplicate entry ana@example.com');
        };
        $set = Harness::set([Harness::document($this->items, [['code' => 'b', 'label' => 'B']])]);
        try {
            $operations->apply($set, Harness::platform(), new ActorRef('alice'), Channel::Console, new PruneOptions());
            self::fail('expected');
        } catch (RuntimeException $e) {
            self::assertSame('Duplicate entry ana@example.com', $e->getMessage(), 'rethrown unchanged');
        }
        self::assertCount(1, $this->audit->records, 'fail closed: audited (ZBK L4)');
        $record = $this->audit->records[0]->toArray();
        self::assertSame(['apply', 'error', ['demo.items'], [], null], [$record['action'], $record['outcome'], $record['providers'], $record['counts'], $record['run_id']]);
        self::assertStringNotContainsString('ana@example.com', json_encode($record, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aHostFailureWhileResolvingTheScopeIsAudited(): void
    {
        $this->resolve = static function (): never {
            throw new RuntimeException('resolver down');
        };
        try {
            $this->operations()->apply($this->tagsSet(), Harness::org(), new ActorRef('alice'), Channel::Http, new PruneOptions(prune: true));
            self::fail('expected');
        } catch (RuntimeException) {
        }
        self::assertCount(1, $this->audit->records);
        self::assertSame(['prune', 'error', ['demo.tags']], [$this->audit->records[0]->action, $this->audit->records[0]->outcome, $this->audit->records[0]->providers]);
    }
}
