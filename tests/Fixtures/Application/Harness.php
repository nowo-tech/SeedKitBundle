<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Closure;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Application\Applier;
use Nowo\SeedKitBundle\Application\ItemNormaliser;
use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Plan\PendingKeys;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator;
use Nowo\SeedKitBundle\Testing\Support\DirectWriteRunner;
use Nowo\SeedKitBundle\Testing\Support\InMemoryLeaseStore;
use Nowo\SeedKitBundle\Testing\Support\InMemoryRunStore;
use Nowo\SeedKitBundle\Testing\Support\SequentialIdGenerator;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Wires the application services over in-memory doubles for the unit tests.
 */
final class Harness
{
    public static function settings(int $batchSize = 500, int $maxErrors = 200, int $previewRows = 200, float $pruneMaxRatio = 0.5): SeedKitSettings
    {
        return new SeedKitSettings(['platform' => false, 'organization' => true], $batchSize, 300, $maxErrors, $previewRows, $pruneMaxRatio);
    }

    /**
     * @param list<SeedProviderInterface> $providers in dependency order
     */
    public static function registry(array $providers): ProviderRegistry
    {
        $factories = [];
        $order     = [];
        foreach ($providers as $provider) {
            $factories[$provider->name()->value] = static fn (): SeedProviderInterface => $provider;
            $order[]                             = $provider->name()->value;
        }

        return new ProviderRegistry(new ServiceLocator($factories), $order);
    }

    public static function normaliser(SeedKitSettings $settings, ?SecretReferenceValidatorInterface $secrets = new PrefixSecretValidator()): ItemNormaliser
    {
        return new ItemNormaliser(new OpisSchemaValidator(), $settings, $secrets);
    }

    /**
     * @param list<SeedProviderInterface> $providers
     */
    public static function planner(array $providers, ?SeedKitSettings $settings = null): Planner
    {
        $settings ??= self::settings();

        return new Planner(self::registry($providers), self::normaliser($settings), $settings);
    }

    public static function applier(
        Planner $planner,
        ?SeedKitSettings $settings = null,
        ?WriteRunnerInterface $writer = null,
        ?LeaseStoreInterface $leases = null,
        ?RunStoreInterface $runs = null,
        ?LoggerInterface $logger = null,
        ?RecordingDispatcher $dispatcher = null,
    ): Applier {
        return new Applier(
            $planner,
            $writer ?? new DirectWriteRunner(),
            $leases ?? new InMemoryLeaseStore(),
            $runs ?? new InMemoryRunStore(),
            new SequentialIdGenerator(),
            $settings ?? self::settings(),
            new MockClock(new DateTimeImmutable('2026-11-24T10:00:00Z')),
            $logger,
            $dispatcher,
        );
    }

    public static function platform(): SeedScope
    {
        return SeedScope::platform();
    }

    public static function org(string $id = 'org-1'): SeedScope
    {
        return SeedScope::parse('organization:' . $id);
    }

    public static function context(?SeedScope $scope = null, string $actor = 'alice', bool $prune = false, bool $canSee = true): SeedContext
    {
        return new SeedContext($scope ?? self::platform(), new ActorRef($actor), Channel::Console, null, new PendingKeys(), $prune, $canSee);
    }

    /**
     * A document over raw items.
     *
     * @param Closure(): iterable<int, mixed>|list<mixed> $items
     */
    public static function document(SeedProviderInterface $provider, array|Closure $items, ?string $kind = null, ?string $exportedFrom = null, ?int $version = null): SeedDocument
    {
        $kind ??= $provider->scopes()[0]->value;

        return new SeedDocument(
            new SeedDocumentHeader(
                $provider->name(),
                $version ?? $provider->version(),
                new ScopeKind($kind),
                null,
                new SeedDocumentMeta(null, $exportedFrom !== null ? new ScopeId($exportedFrom) : null),
            ),
            $items instanceof Closure ? $items : static fn (): iterable => $items,
        );
    }

    /**
     * @param list<SeedDocument> $documents
     */
    public static function set(array $documents): DocumentSet
    {
        return new DocumentSet($documents, DocumentFormat::Json, DocumentHash::ofBytes('doc'));
    }
}
