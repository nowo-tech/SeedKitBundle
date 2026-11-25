<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing;

use Nowo\SeedKitBundle\Application\Applier;
use Nowo\SeedKitBundle\Application\ApplyResult;
use Nowo\SeedKitBundle\Application\Exporter;
use Nowo\SeedKitBundle\Application\ItemNormaliser;
use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Application\SchemaLinter;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Exception\DocumentInvalidException;
use Nowo\SeedKitBundle\Domain\Exception\SecretValueRefusedException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator;
use Nowo\SeedKitBundle\Infrastructure\Writer\DocumentWriter;
use Nowo\SeedKitBundle\Testing\Support\DirectWriteRunner;
use Nowo\SeedKitBundle\Testing\Support\InMemoryLeaseStore;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use Nowo\SeedKitBundle\Testing\Support\SequentialIdGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;

use function array_merge;
use function fopen;
use function fwrite;
use function is_resource;
use function preg_replace;
use function rewind;
use function str_contains;
use function stream_get_contents;

/**
 * The provider contract test kit (contract section 19, Beacon QB-SK-3), shipped so hosts run the same checks on their
 * providers: extend it in a test of your provider, implement the hooks, and keep the data of your test database
 * isolated per test. It checks:
 *
 * - the schema passes the lint (contract section 7.3);
 * - applying the sample items, then planning them again, plans 0 changes (SC-002), and `export current` read back
 *   plans 0 changes (round trip, SC-001); a second apply writes nothing;
 * - two exports return the same bytes (stateless, worker-safe) and contain none of {@see knownSecretValues()} (SC-005);
 * - an item that would grant more than the actor holds is refused before any write ({@see escalatingItem()}, QB-SK-3);
 * - a restricted actor never sees rows hidden from it ({@see restrictedActor()}, contract F9).
 *
 * phpunit/phpunit is a dev dependency of the host (suggested by the bundle).
 */
abstract class SeedProviderContractTestCase extends TestCase
{
    /**
     * The provider under test (a fresh instance wired to the test database).
     */
    abstract protected function createProvider(): SeedProviderInterface;

    /**
     * The scope the tests run in (resolved: the kit does not call a scope resolver).
     */
    abstract protected function scope(): SeedScope;

    /**
     * The actor of the tests (allowed to read and write every sample row).
     */
    abstract protected function actor(): ActorRef;

    /**
     * Valid raw items whose first apply writes at least one row.
     *
     * @return list<array<string, mixed>>
     */
    abstract protected function sampleItems(): array;

    /**
     * The providers the tested provider depends on (registered so references resolve; seed their rows yourself).
     *
     * @return list<SeedProviderInterface>
     */
    protected function dependencies(): array
    {
        return [];
    }

    /**
     * The secret-reference validator of the host (null: every secret reference is refused / omitted).
     */
    protected function secretValidator(): ?SecretReferenceValidatorInterface
    {
        return null;
    }

    /**
     * Secret values stored in the test data that must never appear in an export (SC-005).
     *
     * @return list<string>
     */
    protected function knownSecretValues(): array
    {
        return [];
    }

    /**
     * A raw item that would grant the actor more than it holds (null: the provider grants nothing; skipped).
     *
     * @return array<string, mixed>|null
     */
    protected function escalatingItem(): ?array
    {
        return null;
    }

    /**
     * An actor that may see only part of the rows (null: no row-level visibility; skipped).
     */
    protected function restrictedActor(): ?ActorRef
    {
        return null;
    }

    /**
     * Keys of rows (after the sample apply) the restricted actor must not see.
     *
     * @return list<NaturalKey>
     */
    protected function keysHiddenFromRestrictedActor(): array
    {
        return [];
    }

    /**
     * The settings of the planner (default: the provider's scope kinds, batch of 500).
     */
    protected function settings(): SeedKitSettings
    {
        $kinds = [];
        foreach (array_merge([$this->createProvider()], $this->dependencies()) as $provider) {
            foreach ($provider->scopes() as $kind) {
                $kinds[$kind->value] = $kind->value !== 'platform';
            }
        }

        return new SeedKitSettings($kinds);
    }

    /**
     * The schema passes the lint.
     */
    #[Test]
    public function schemaPassesTheLint(): void
    {
        self::assertSame([], (new SchemaLinter())->lint($this->createProvider())['errors']);
    }

    /**
     * Apply, then re-plan and round trip: zero changes; a second apply writes nothing (SC-001, SC-002).
     */
    #[Test]
    public function applyIsIdempotentAndExportRoundTrips(): void
    {
        $first = $this->plan($this->documentSet($this->sampleItems()));
        self::assertGreaterThan(0, $first->changes(), 'The sample items must write at least one row on the first apply.');
        $applied = $this->apply($this->documentSet($this->sampleItems()));
        self::assertNotNull($applied->runId);

        self::assertSame(0, $this->plan($this->documentSet($this->sampleItems()))->changes(), 'Re-planning the applied items must plan no change.');
        self::assertNull($this->apply($this->documentSet($this->sampleItems()))->runId, 'A second apply must write nothing.');

        $exported = $this->export($this->actor());
        $set      = $this->read($exported);
        self::assertSame(0, $this->plan($set)->changes(), 'export current → apply must plan no change (round trip).');
    }

    /**
     * Exports are byte-stable and never carry a secret value (SC-005).
     */
    #[Test]
    public function exportsAreStableAndCarryNoSecretValue(): void
    {
        $this->apply($this->documentSet($this->sampleItems()));
        $first  = $this->export($this->actor());
        $second = $this->export($this->actor());
        self::assertSame($this->withoutExportTime($first), $this->withoutExportTime($second), 'Two exports in one process must be identical (stateless provider).');
        foreach ($this->knownSecretValues() as $secret) {
            self::assertFalse(str_contains($first, $secret), 'An export contains a secret value.');
        }
    }

    /**
     * An item granting more than the actor holds is refused before any write (QB-SK-3).
     */
    #[Test]
    public function refusesItemsThatEscalate(): void
    {
        $item = $this->escalatingItem();
        if ($item === null) {
            self::markTestSkipped('The provider grants nothing (escalatingItem() is null).');
        }
        try {
            $this->plan($this->documentSet([$item]));
            self::fail('An item granting more than the actor holds must be refused.');
        } catch (DocumentInvalidException|SecretValueRefusedException $e) {
            self::assertNotSame([], $e->errors);
        }
    }

    /**
     * A restricted actor never sees rows hidden from it (row-level visibility, F9).
     */
    #[Test]
    public function restrictedActorSeesOnlyItsRows(): void
    {
        $actor = $this->restrictedActor();
        if (!$actor instanceof ActorRef) {
            self::markTestSkipped('The provider has no row-level visibility (restrictedActor() is null).');
        }
        $this->apply($this->documentSet($this->sampleItems()));
        $export = $this->export($actor);
        foreach ($this->keysHiddenFromRestrictedActor() as $key) {
            foreach ($key->components as $component) {
                self::assertFalse(str_contains($export, (string) $component), 'A restricted actor sees a hidden row.');
            }
        }
    }

    /**
     * A document set of one document of the provider.
     *
     * @param list<array<string, mixed>> $items
     */
    protected function documentSet(array $items): DocumentSet
    {
        $provider = $this->createProvider();
        $header   = new SeedDocumentHeader($provider->name(), $provider->version(), $this->scope()->kind);

        return new DocumentSet([new SeedDocument($header, static fn (): array => $items)], DocumentFormat::Yaml, DocumentHash::ofBytes('contract-test'));
    }

    /**
     * The dry run of a set.
     */
    protected function plan(DocumentSet $set): SeedPlan
    {
        $planner = $this->planner();

        return $planner->plan($planner->prepare($set, $this->scope()), $this->context($this->actor()), new PruneOptions());
    }

    /**
     * Applies a set.
     */
    protected function apply(DocumentSet $set): ApplyResult
    {
        $planner = $this->planner();
        $applier = new Applier($planner, new DirectWriteRunner(), new InMemoryLeaseStore(), new InMemoryRunStore(), new SequentialIdGenerator(), $this->settings(), $this->clock(), null);

        return $applier->apply($set, $planner->prepare($set, $this->scope()), $this->context($this->actor()), new PruneOptions(), null);
    }

    /**
     * The current export (YAML) of an actor.
     */
    protected function export(ActorRef $actor): string
    {
        $stream = fopen('php://memory', 'w+');
        if (!is_resource($stream)) {
            throw new RuntimeException('No memory stream.');
        }
        (new Exporter(new DocumentWriter(), $this->settings(), $this->clock(), $this->secretValidator()))
            ->export($this->createProvider(), $this->context($actor), ExportMode::Current, DocumentFormat::Yaml, $stream);
        rewind($stream);

        return (string) stream_get_contents($stream);
    }

    /**
     * Reads an exported YAML text.
     */
    protected function read(string $yaml): DocumentSet
    {
        $stream = fopen('php://memory', 'w+');
        if (!is_resource($stream)) {
            throw new RuntimeException('No memory stream.');
        }
        fwrite($stream, $yaml);
        rewind($stream);

        return (new DocumentReader())->read($stream, DocumentFormat::Yaml, new ReaderLimits());
    }

    /**
     * The planner over the provider and its dependencies.
     */
    protected function planner(): Planner
    {
        $providers = [];
        $order     = [];
        foreach (array_merge($this->dependencies(), [$this->createProvider()]) as $provider) {
            $providers[$provider->name()->value] = $provider;
            $order[]                             = $provider->name()->value;
        }
        $container = new class($providers) implements ContainerInterface {
            /**
             * Holds the providers by name.
             *
             * @param array<string, SeedProviderInterface> $providers
             */
            public function __construct(private readonly array $providers)
            {
            }

            /**
             * The provider of a name.
             */
            public function get(string $id): SeedProviderInterface
            {
                return $this->providers[$id] ?? throw new RuntimeException('Unknown provider.');
            }

            /**
             * Whether a provider has the name.
             */
            public function has(string $id): bool
            {
                return isset($this->providers[$id]);
            }
        };
        $settings = $this->settings();

        return new Planner(new ProviderRegistry($container, $order), new ItemNormaliser(new OpisSchemaValidator(), $settings, $this->secretValidator()), $settings);
    }

    /**
     * A console context of an actor in the test scope.
     */
    protected function context(ActorRef $actor): SeedContext
    {
        return new SeedContext($this->scope(), $actor, Channel::Console, null, new PendingKeys());
    }

    /**
     * The clock of the kit.
     */
    protected function clock(): ClockInterface
    {
        return new NativeClock();
    }

    /**
     * The export without its `exportedAt` instant.
     */
    private function withoutExportTime(string $export): string
    {
        return (string) preg_replace('/exportedAt: \'[^\']*\'/', 'exportedAt: X', $export);
    }
}
