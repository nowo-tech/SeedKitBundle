<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Application;

use Closure;
use DateTimeImmutable;
use Nowo\SeedKitBundle\Application\Exporter;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\CapturingWriter;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\ScriptedProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Export (contract 8): header, row order re-checked, secrets fail closed, read-only last.
 */
final class ExporterTest extends TestCase
{
    private CapturingWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new CapturingWriter();
    }

    private function exporter(?SecretReferenceValidatorInterface $secrets = new PrefixSecretValidator()): Exporter
    {
        return new Exporter($this->writer, new SeedKitSettings(generator: 'seed-kit test'), new MockClock(new DateTimeImmutable('2026-11-24T10:00:00Z')), $secrets);
    }

    /**
     * @return resource
     */
    private static function stream()
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);

        return $stream;
    }

    #[Test]
    public function exportsTheEmptyTemplate(): void
    {
        $tags = new TagProvider(new InMemoryStore());
        $rows = $this->exporter()->export($tags, Harness::context(Harness::org()), ExportMode::Empty, DocumentFormat::Yaml, self::stream());
        self::assertSame(1, $rows);
        $document = $this->writer->document;
        self::assertNotNull($document);
        self::assertSame(ExportMode::Empty, $document->mode);
        self::assertSame(DocumentFormat::Yaml, $this->writer->format);
        self::assertSame('demo.tags', $document->header->provider->value);
        self::assertSame('organization', $document->header->scope->value);
        self::assertSame('demo.tags.v1.schema.json', $document->header->schemaRef);
        self::assertNull($document->header->meta->exportedAt);
        self::assertNull($document->header->meta->exportedFrom);
        self::assertSame('seed-kit test', $document->header->meta->generator);
        self::assertSame([['slug' => 'news', 'label' => 'News']], $this->writer->items);
    }

    #[Test]
    public function exportsCurrentRowsWithSecretsCheckedAndReadonlyLast(): void
    {
        $store                             = new InMemoryStore();
        $store->rows['demo.tags']['["b"]'] = ['createdAt' => '2026-01-01', 'apiToken' => 'vault:ok-2', 'label' => 'B', 'slug' => 'b'];
        $store->rows['demo.tags']['["a"]'] = ['slug' => 'a', 'label' => 'A', 'apiToken' => 'vault:foreign', 'color' => 'red'];
        $store->rows['demo.tags']['["c"]'] = ['slug' => 'c', 'label' => 'C', 'apiToken' => null];
        $rows                              = $this->exporter()->export(new TagProvider($store), Harness::context(Harness::org('org-9')), ExportMode::Current, DocumentFormat::Json, self::stream());
        self::assertSame(3, $rows);
        self::assertSame([
            ['slug' => 'a', 'label' => 'A', 'color' => 'red'],
            ['slug' => 'b', 'label' => 'B', 'apiToken' => 'vault:ok-2', 'createdAt' => '2026-01-01'],
            ['slug' => 'c', 'label' => 'C'],
        ], $this->writer->items);
        self::assertSame(['slug', 'label', 'apiToken', 'createdAt'], array_keys($this->writer->items[1]));
        self::assertNotNull($this->writer->document);
        $meta = $this->writer->document->header->meta;
        self::assertSame('2026-11-24T10:00:00+00:00', $meta->exportedAt);
        self::assertSame('org-9', $meta->exportedFrom?->value);
    }

    #[Test]
    public function withoutAValidatorSecretsAreOmitted(): void
    {
        $store                             = new InMemoryStore();
        $store->rows['demo.tags']['["a"]'] = ['slug' => 'a', 'label' => 'A', 'apiToken' => 'vault:ok-1'];
        $this->exporter(null)->export(new TagProvider($store), Harness::context(), ExportMode::Current, DocumentFormat::Json, self::stream());
        self::assertSame([['slug' => 'a', 'label' => 'A']], $this->writer->items);
    }

    #[Test]
    public function anEmptyCurrentExportHasNoItem(): void
    {
        self::assertSame(0, $this->exporter()->export(new TagProvider(new InMemoryStore()), Harness::context(), ExportMode::Current, DocumentFormat::Json, self::stream()));
        self::assertSame([], $this->writer->items);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function item(string $code, array $data = []): SeedItem
    {
        return new SeedItem(0, new NaturalKey([$code]), ['code' => $code] + $data);
    }

    /**
     * @return iterable<string, array{Closure(): iterable<mixed>}>
     */
    public static function brokenExports(): iterable
    {
        yield 'not an item' => [static fn (): iterable => [['code' => 'a']]];
        yield 'unordered' => [static fn (): iterable => [self::item('b'), self::item('a')]];
        yield 'repeated' => [static fn (): iterable => [self::item('a'), self::item('a')]];
        yield 'key count' => [static fn (): iterable => [new SeedItem(0, new NaturalKey(['a', 'x']), ['code' => 'a'])]];
        yield 'key vs data' => [static fn (): iterable => [new SeedItem(0, new NaturalKey(['a']), ['code' => 'b'])]];
        yield 'key folded' => [static fn (): iterable => [new SeedItem(0, new NaturalKey(['A']), ['code' => 'a'])]];
        yield 'unknown property' => [static fn (): iterable => [self::item('a', ['password' => 'x'])]];
        yield 'plaintext secret' => [static fn (): iterable => [self::item('a', ['pin' => '1234'])]];
    }

    /**
     * @param Closure(): iterable<mixed> $rows
     */
    #[Test]
    #[DataProvider('brokenExports')]
    public function failsClosedOnABrokenProvider(Closure $rows): void
    {
        $provider = new ScriptedProvider('demo.items', [
            'type'       => 'object',
            'x-seed-key' => ['code'],
            'properties' => ['code' => ['type' => 'string'], 'pin' => ['type' => 'string', 'x-seed-secret' => true]],
        ]);
        $provider->onExportCurrent = static fn (): iterable => $rows();
        $this->expectException(ProviderContractException::class);
        $this->exporter()->export($provider, Harness::context(), ExportMode::Current, DocumentFormat::JsonLines, self::stream());
    }

    #[Test]
    public function aSecretReferenceOfAnotherScopeIsOmitted(): void
    {
        $provider = new ScriptedProvider('demo.items', [
            'type'       => 'object',
            'x-seed-key' => ['code'],
            'properties' => ['code' => ['type' => 'string'], 'pin' => ['type' => 'string', 'x-seed-secret' => true]],
        ]);
        $provider->onExportCurrent = static fn (): iterable => [
            new SeedItem(0, new NaturalKey(['a']), ['code' => 'a', 'pin' => new SecretReference('vault:elsewhere')]),
            new SeedItem(0, new NaturalKey(['b']), ['code' => 'b', 'pin' => new SecretReference('vault:ok-b')]),
        ];
        $this->exporter()->export($provider, Harness::context(), ExportMode::Current, DocumentFormat::Json, self::stream());
        self::assertSame([['code' => 'a'], ['code' => 'b', 'pin' => 'vault:ok-b']], $this->writer->items);
    }
}
