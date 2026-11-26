<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Run;

use Nowo\SeedKitBundle\Application\PruneOptions;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Run\GzipRunDocumentCodec;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\Harness;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\ArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\TagProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function gzencode;
use function iterator_to_array;
use function rewind;

/**
 * The stored form of a UI run's document (contract 11, Q-SK-5): round trip of headers, items, format and raw hash, so
 * the decoded set plans to the same plan hash; a broken blob is "not available", never an error page with details.
 */
final class GzipRunDocumentCodecTest extends TestCase
{
    private function read(string $text): DocumentSet
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return (new DocumentReader())->read($stream, null, new ReaderLimits());
    }

    #[Test]
    public function aDocumentSetRoundTripsToTheSamePlan(): void
    {
        $yaml  = "provider: demo.tags\nversion: 1\nscope: organization\n\$schema: demo.tags.v1.schema.json\nmeta: { exportedAt: '2026-11-24T10:00:00+00:00', exportedFrom: org-2, generator: seed-kit }\nitems:\n  - { slug: News, label: 'Ünïcode \"quoted\"\\nline', color: red }\n  - { slug: big, label: '12345678901234567890' }\n---\nprovider: demo.articles\nversion: 1\nscope: organization\nitems:\n  - { code: a1, title: 'A/1', tag: news, level: 3 }\n";
        $set   = $this->read($yaml);
        $codec = new GzipRunDocumentCodec();
        $back  = $codec->decode($codec->encode($set));

        self::assertSame($set->hash->value, $back->hash->value);
        self::assertSame(DocumentFormat::Yaml, $back->format);
        self::assertCount(2, $back->documents);
        self::assertSame('demo.tags.v1.schema.json', $back->documents[0]->header->schemaRef);
        self::assertSame('org-2', $back->documents[0]->header->meta->exportedFrom?->value);
        self::assertSame('seed-kit', $back->documents[0]->header->meta->generator);
        self::assertSame(iterator_to_array($set->documents[0]->items()), iterator_to_array($back->documents[0]->items()));
        self::assertSame(iterator_to_array($set->documents[1]->items()), iterator_to_array($back->documents[1]->items()));
        self::assertSame(iterator_to_array($back->documents[1]->items()), iterator_to_array($back->documents[1]->items()), 'items can be read again');

        $store   = new InMemoryStore();
        $planner = Harness::planner([new TagProvider($store), new ArticleProvider($store)]);
        $hash    = static fn (DocumentSet $s): string => $planner->plan($planner->prepare($s, Harness::org()), Harness::context(Harness::org()), new PruneOptions())->hash->value;
        self::assertSame($hash($set), $hash($back), 'the stored form plans to the same hash');
    }

    #[Test]
    public function anEmptyDocumentAndFloatsRoundTrip(): void
    {
        $codec = new GzipRunDocumentCodec();
        $set   = Harness::set([Harness::document(new TagProvider(new InMemoryStore()), [])]);
        $back  = $codec->decode($codec->encode($set));
        self::assertSame([], iterator_to_array($back->documents[0]->items()));
        self::assertNull($back->documents[0]->header->meta->exportedFrom);

        $set  = Harness::set([Harness::document(new TagProvider(new InMemoryStore()), [['n' => 1.0, 'm' => 0.1]])]);
        $back = $codec->decode($codec->encode($set));
        self::assertSame([['n' => 1.0, 'm' => 0.1]], iterator_to_array($back->documents[0]->items()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function broken(): iterable
    {
        yield 'not gzip' => ['plain'];
        yield 'empty' => [(string) gzencode('')];
        yield 'no newline' => [(string) gzencode('{"v":1}')];
        yield 'bad version' => [(string) gzencode('{"v":2,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n")];
        yield 'bad format' => [(string) gzencode('{"v":1,"format":"csv","hash":"sha256:' . str_repeat('a', 64) . "\"}\n")];
        yield 'bad hash' => [(string) gzencode("{\"v\":1,\"format\":\"yaml\",\"hash\":\"md5\"}\n")];
        yield 'item before header' => [(string) gzencode('{"v":1,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n{\"i\":1}\n")];
        yield 'bad header' => [(string) gzencode('{"v":1,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n{\"h\":{\"provider\":\"x\"}}\n")];
        yield 'bad provider' => [(string) gzencode('{"v":1,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n{\"h\":{\"provider\":\"X Y\",\"version\":1,\"scope\":\"platform\"}}\n")];
        yield 'not json' => [(string) gzencode('{"v":1,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n{oops\n")];
        yield 'scalar line' => [(string) gzencode("1\n")];
        yield 'too large' => [(string) gzencode(str_repeat(' ', 2048))];
    }

    #[Test]
    #[DataProvider('broken')]
    public function aBrokenBlobIsNotAvailable(string $blob): void
    {
        $this->expectException(RunNotAvailableException::class);
        (new GzipRunDocumentCodec(1024))->decode($blob);
    }

    #[Test]
    public function aBrokenItemLineIsNotAvailableWhenRead(): void
    {
        $blob = (string) gzencode('{"v":1,"format":"yaml","hash":"sha256:' . str_repeat('a', 64) . "\"}\n{\"h\":{\"provider\":\"demo.tags\",\"version\":1,\"scope\":\"platform\"}}\n{\"i\":{oops\n");
        $set  = (new GzipRunDocumentCodec())->decode($blob);

        $this->expectException(RunNotAvailableException::class);
        iterator_to_array($set->documents[0]->items());
    }
}
