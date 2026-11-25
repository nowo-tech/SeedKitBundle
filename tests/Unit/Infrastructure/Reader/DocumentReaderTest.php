<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Reader;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Tests\Fixtures\Reader\FailingReadStream;
use Nowo\SeedKitBundle\Tests\Fixtures\Reader\RefusingOpenStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fclose;
use function fopen;
use function fwrite;
use function gc_collect_cycles;
use function hash;
use function is_float;
use function is_infinite;
use function json_encode;
use function max;
use function memory_get_usage;
use function rewind;
use function sprintf;
use function str_repeat;
use function stream_wrapper_register;
use function stream_wrapper_unregister;
use function strlen;
use function tmpfile;

use const JSON_THROW_ON_ERROR;

/**
 * The seed file reader (contract sections 8 and 13.3): formats, header rules, caps, encodings, the hostile YAML / JSON
 * corpus and JSON Lines streaming.
 */
final class DocumentReaderTest extends TestCase
{
    private const HEADER_YAML = "provider: crm.contacts\nversion: 1\nscope: organization\n";

    private const HEADER_JSON = '{"provider":"crm.contacts","version":1,"scope":"organization"}';

    #[Test]
    public function readsAYamlDocumentWithItsHeaderHashAndReIterableItems(): void
    {
        $text = "\$schema: crm.contacts.v1.schema.json\n" . self::HEADER_YAML
            . "meta: { exportedAt: '2026-11-24T10:00:00+00:00', exportedFrom: org-1, generator: 'seed-kit 0.1.0' }\n"
            . "items:\n  - { email: ana@example.com, tags: [customer] }\n  - email: luis@example.com\n";

        $set = $this->read($text);

        self::assertSame(DocumentFormat::Yaml, $set->format);
        self::assertSame('sha256:' . hash('sha256', $text), $set->hash->value);
        self::assertCount(1, $set->documents);
        $header = $set->documents[0]->header;
        self::assertSame('crm.contacts', $header->provider->value);
        self::assertSame(1, $header->version);
        self::assertSame('organization', $header->scope->value);
        self::assertSame('crm.contacts.v1.schema.json', $header->schemaRef);
        self::assertSame('2026-11-24T10:00:00+00:00', $header->meta->exportedAt);
        self::assertSame('org-1', $header->meta->exportedFrom?->value);
        self::assertSame('seed-kit 0.1.0', $header->meta->generator);
        $expected = [['email' => 'ana@example.com', 'tags' => ['customer']], ['email' => 'luis@example.com']];
        self::assertSame($expected, $this->items($set->documents[0]));
        self::assertSame($expected, $this->items($set->documents[0]));
    }

    #[Test]
    public function readsAJsonDocumentWithoutMeta(): void
    {
        $set = $this->read('{"provider":"crm.contacts","version":2,"scope":"platform","items":[{"a":1}]}');

        self::assertSame(DocumentFormat::Json, $set->format);
        $header = $set->documents[0]->header;
        self::assertSame(2, $header->version);
        self::assertNull($header->schemaRef);
        self::assertNull($header->meta->exportedAt);
        self::assertNull($header->meta->exportedFrom);
        self::assertNull($header->meta->generator);
        self::assertSame([['a' => 1]], $this->items($set->documents[0]));
    }

    #[Test]
    public function readsAMultiDocumentYamlStreamInFileOrder(): void
    {
        $text = "# a run with two providers\n--- # first\n" . self::HEADER_YAML . "items: []\n"
            . "---\nprovider: crm.companies\nversion: 1\nscope: organization\nitems: [{ slug: acme }]\n...\n# trailing comment\n";

        $set = $this->read($text);

        self::assertCount(2, $set->documents);
        self::assertSame('crm.contacts', $set->documents[0]->header->provider->value);
        self::assertSame([], $this->items($set->documents[0]));
        self::assertSame('crm.companies', $set->documents[1]->header->provider->value);
        self::assertSame([['slug' => 'acme']], $this->items($set->documents[1]));
    }

    #[Test]
    public function aYamlFileWithOnlyCommentsHasNoDocument(): void
    {
        self::assertSame([], $this->read("# nothing here\n\n# still nothing\n", DocumentFormat::Yaml)->documents);
    }

    #[Test]
    public function readsAJsonDocumentsObject(): void
    {
        $text = '{"documents":[{"provider":"a.one","version":1,"scope":"platform","items":[]},{"provider":"a.two","version":1,"scope":"platform","items":[1]}]}';

        $set = $this->read($text);

        self::assertCount(2, $set->documents);
        self::assertSame('a.one', $set->documents[0]->header->provider->value);
        self::assertSame('a.two', $set->documents[1]->header->provider->value);
        self::assertSame([1], $this->items($set->documents[1]));
    }

    #[Test]
    public function theDocumentCountIsCappedForYamlAndJson(): void
    {
        $doc  = self::HEADER_YAML . "items: []\n";
        $json = '{"provider":"a.one","version":1,"scope":"platform","items":[]}';

        $this->assertReadFails('---' . "\n" . $doc . "---\n" . $doc, 'too_many_documents', null, null, new ReaderLimits(maxDocuments: 1));
        $this->assertReadFails('{"documents":[' . $json . ',' . $json . ']}', 'too_many_documents', null, null, new ReaderLimits(maxDocuments: 1));
        self::assertCount(2, $this->read('{"documents":[' . $json . ',' . $json . ']}', null, new ReaderLimits(maxDocuments: 2))->documents);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDocumentsObjects(): iterable
    {
        $doc = '{"provider":"a.one","version":1,"scope":"platform","items":[]}';

        yield 'extra key next to documents' => ['{"documents":[' . $doc . '],"provider":"a.one"}'];
        yield 'documents is a map' => ['{"documents":{"a":' . $doc . '}}'];
        yield 'documents is a string' => ['{"documents":"x"}'];
    }

    #[Test]
    #[DataProvider('invalidDocumentsObjects')]
    public function refusesAnInvalidDocumentsObject(string $json): void
    {
        $this->assertReadFails($json, 'unknown_key');
    }

    /**
     * @return iterable<string, array{string, DocumentFormat, string}>
     */
    public static function notADocument(): iterable
    {
        yield 'json scalar' => ['"text"', DocumentFormat::Json, 'not_a_document'];
        yield 'json list' => ['[1, 2]', DocumentFormat::Json, 'not_a_document'];
        yield 'json documents entry is a list' => ['{"documents":[[1]]}', DocumentFormat::Json, 'not_a_document'];
        yield 'yaml scalar' => ["just text\n", DocumentFormat::Yaml, 'not_a_document'];
        yield 'yaml sequence' => ["- a\n- b\n", DocumentFormat::Yaml, 'not_a_document'];
        yield 'empty json object' => ['{}', DocumentFormat::Json, 'header_invalid'];
        yield 'empty json list' => ['[]', DocumentFormat::Json, 'header_invalid'];
    }

    #[Test]
    #[DataProvider('notADocument')]
    public function refusesTreesThatAreNotDocuments(string $text, DocumentFormat $format, string $reason): void
    {
        $this->assertReadFails($text, $reason, null, $format);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidHeaders(): iterable
    {
        $schema = '"$schema":';

        yield 'unknown top-level key' => ['{"provider":"a.one","version":1,"scope":"platform","items":[],"extra":1}', 'unknown_key'];
        yield 'missing provider' => ['{"version":1,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'invalid provider name' => ['{"provider":"Bad Name","version":1,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'version as a string' => ['{"provider":"a.one","version":"1","scope":"platform","items":[]}', 'header_invalid'];
        yield 'version 0' => ['{"provider":"a.one","version":0,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'version 1000' => ['{"provider":"a.one","version":1000,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'invalid scope kind' => ['{"provider":"a.one","version":1,"scope":"Org","items":[]}', 'header_invalid'];
        yield 'missing scope' => ['{"provider":"a.one","version":1,"items":[]}', 'header_invalid'];
        yield 'schema not a string' => ['{' . $schema . '1,"provider":"a.one","version":1,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'schema over 255 bytes' => ['{' . $schema . '"' . str_repeat('s', 256) . '","provider":"a.one","version":1,"scope":"platform","items":[]}', 'header_invalid'];
        yield 'missing items' => ['{"provider":"a.one","version":1,"scope":"platform"}', 'header_invalid'];
        yield 'meta is a list' => ['{"provider":"a.one","version":1,"scope":"platform","meta":["x"],"items":[]}', 'header_invalid'];
        yield 'meta is a string' => ['{"provider":"a.one","version":1,"scope":"platform","meta":"x","items":[]}', 'header_invalid'];
        yield 'unknown meta key' => ['{"provider":"a.one","version":1,"scope":"platform","meta":{"user":"x"},"items":[]}', 'header_invalid'];
        yield 'meta value not a string' => ['{"provider":"a.one","version":1,"scope":"platform","meta":{"exportedAt":1},"items":[]}', 'header_invalid'];
        yield 'generator over 64 bytes' => ['{"provider":"a.one","version":1,"scope":"platform","meta":{"generator":"' . str_repeat('g', 65) . '"},"items":[]}', 'header_invalid'];
        yield 'blank exportedFrom' => ['{"provider":"a.one","version":1,"scope":"platform","meta":{"exportedFrom":" x"},"items":[]}', 'header_invalid'];
        yield 'items is a map' => ['{"provider":"a.one","version":1,"scope":"platform","items":{"a":1}}', 'items_not_a_list'];
        yield 'items is a string' => ['{"provider":"a.one","version":1,"scope":"platform","items":"x"}', 'items_not_a_list'];
    }

    #[Test]
    #[DataProvider('invalidHeaders')]
    public function refusesInvalidHeaders(string $json, string $reason): void
    {
        $this->assertReadFails($json, $reason, null, DocumentFormat::Json);
    }

    #[Test]
    public function acceptsTheLongestSchemaRefAndGeneratorAndAnEmptyMeta(): void
    {
        $schema    = str_repeat('s', 255);
        $generator = str_repeat('g', 64);
        $set       = $this->read(json_encode(['$schema' => $schema, 'provider' => 'a.one', 'version' => 999, 'scope' => 'platform', 'meta' => ['generator' => $generator], 'items' => []], JSON_THROW_ON_ERROR));
        $empty     = $this->read('{"provider":"a.one","version":1,"scope":"platform","meta":{},"items":[]}');

        self::assertSame($schema, $set->documents[0]->header->schemaRef);
        self::assertSame($generator, $set->documents[0]->header->meta->generator);
        self::assertSame(999, $set->documents[0]->header->version);
        self::assertNull($empty->documents[0]->header->meta->generator);
    }

    /**
     * Hostile YAML (contract 13.3): refused before the parser sees it, with the offending line.
     *
     * @return iterable<string, array{string, string, int}>
     */
    public static function hostileYaml(): iterable
    {
        $h = self::HEADER_YAML . "items:\n";

        yield 'billion laughs anchors' => ["a: &a [x, x, x]\nb: &b [*a, *a, *a]\nc: [*b, *b, *b]\n", 'yaml_anchor', 1];
        yield 'billion laughs in items' => [$h . "  - &a [x, x]\n  - [*a, *a]\n", 'yaml_anchor', 5];
        yield 'alias in a flow mapping' => [$h . "  - { name: a, ref: *x }\n", 'yaml_alias', 5];
        yield 'anchor in a flow sequence' => [$h . "  - [a, &b c]\n", 'yaml_anchor', 5];
        yield 'php object tag' => [$h . "  - !php/object 'O:8:\"stdClass\":0:{}'\n", 'yaml_tag', 5];
        yield 'php const tag' => [$h . "  - name: !php/const PHP_INT_MAX\n", 'yaml_tag', 5];
        yield 'binary tag' => [$h . "  - blob: !!binary aGVsbG8=\n", 'yaml_tag', 5];
        yield 'str tag on the version' => ["provider: crm.contacts\nversion: !!str 1\n", 'yaml_tag', 2];
        yield 'merge key' => [$h . "  - <<: { a: 1 }\n", 'yaml_merge_key', 5];
        yield 'merge key with spaces' => [$h . "  - name: x\n    << : { a: 1 }\n", 'yaml_merge_key', 6];
        yield 'yaml directive' => ["%YAML 1.2\n---\n" . self::HEADER_YAML, 'yaml_directive', 1];
        yield 'tag after a document marker' => ["--- !custom\n" . self::HEADER_YAML, 'yaml_tag', 1];
        yield 'explicit key with an anchor' => [$h . "  - ? &k key\n    : value\n", 'yaml_explicit_key', 5];
        yield 'plain scalar continued with an alias' => [$h . "  - name: foo\n    *bar\n", 'yaml_alias', 6];
        // ZBK M1: a quoted flow key followed directly by `:` (JSON style), as Symfony Yaml accepts it.
        $i = self::HEADER_YAML . 'items: ';
        yield 'anchor after a quoted key and a bare colon' => [$i . "[{\"email\":\"a\",\"t\":&a [1,2,3]}, {\"email\":\"b\",\"t\":*a}]\n", 'yaml_anchor', 4];
        yield 'alias after a quoted key and a bare colon' => [$i . "[{\"email\":\"b\",\"t\":*a}]\n", 'yaml_alias', 4];
        yield 'binary tag after a quoted key and a bare colon' => [$i . "[{\"email\":!!binary YQBi/w==}]\n", 'yaml_tag', 4];
        yield 'str tag after a single-quoted key' => [$i . "[{'email':!!str 1}]\n", 'yaml_tag', 4];
        yield 'anchor after a single-quoted key' => [$i . "[{'email':'a','t':&a [1]}, {'email':'b','t':*a}]\n", 'yaml_anchor', 4];
        yield 'alias after a quoted key, spaces and a colon' => [$i . "[{\"t\"  :*a}]\n", 'yaml_alias', 4];
        yield 'alias after a run of colons' => [$i . "[{t: :*a}]\n", 'yaml_alias', 4];
        yield 'alias after a quoted key and a run of colons' => [$i . "[{\"t\"::*a}]\n", 'yaml_alias', 4];
        yield 'alias after a quoted key with the colon on the next line' => [$i . "[{\"t\"\n  :*a}]\n", 'yaml_alias', 5];
        yield 'alias after a quoted block key and a bare colon' => [$h . "  - \"t\":*a\n", 'yaml_alias', 5];
        yield 'tag after a colon inside a flow plain scalar' => [$i . "[{t: a:!!binary x}]\n", 'yaml_tag', 4];
        // ZBK-v M1-b: Symfony reads `:|p`, `:"p` and `? "a\"x` as plain keys.
        yield 'binary tag after a plain key starting with :|' => [$h . "  - email: a\n    :|p: !!binary aGVsbG8=\n", 'yaml_tag', 6];
        yield 'str tag after a plain key starting with :|' => [$h . "  - email: a\n    :|p: !!str 1\n", 'yaml_tag', 6];
        yield 'alias after a plain key starting with :"' => [$h . "  - email: a\n    :\"p: *a\n", 'yaml_alias', 6];
        yield 'explicit key with an escaped quote' => [$h . "  - ? \"a\\\"x: *a\n", 'yaml_explicit_key', 5];
        // ZBK-v2 probes: scanner / Symfony desyncs are refused, never guessed.
        yield 'tab comment hiding a quote' => [$h . "  - email: a\n    n: x\t# y: '\n    m: !!binary aGVsbG8=\n    # '\n", 'yaml_ambiguous', 6];
        yield 'tab comment line hiding a quote' => [$h . "\t# x: '\n  - m: !!binary aGVsbG8=\n# '\n", 'yaml_ambiguous', 5];
        yield 'quote opening a plain continuation line' => [$h . "  - c: x\n      \"y\n    d: !!binary aGVsbG8=\n    e: '\"'\n", 'yaml_ambiguous', 6];
        // ZBK-v3 probe: a quote on a line that Symfony folds into a flow plain scalar.
        yield 'quote continuing a flow plain scalar' => [self::HEADER_YAML . "items: [{email: a, t: [x\n  \"y, !!binary aGVsbG8=, z\"]}]\n", 'yaml_ambiguous', 5];
        yield 'dash inside a flow hiding a quote' => [$h . "  - [- -   ',!x ]\n", 'yaml_tag', 5];
    }

    #[Test]
    #[DataProvider('hostileYaml')]
    public function refusesHostileYaml(string $text, string $reason, int $line): void
    {
        $this->assertReadFails($text, $reason, $line, DocumentFormat::Yaml);
    }

    #[Test]
    public function keepsQuotedAndInnerYamlIndicatorsAsTextAndIgnoresCommentsAndBlockScalars(): void
    {
        $text = "# &anchor *alias !tag <<: x %YAML\n" . self::HEADER_YAML . "items: # *not-an-alias\n"
            . "  - name: \"*star\"\n"
            . "    title: '&amp'\n"
            . "    quote: 'it''s *'\n"
            . "    escaped: \"say \\\"*hi\\\"\"\n"
            . "    plain: a&b*c!d\n"
            . "    hash: a#&b\n"
            . "    merge: '<<'\n"
            . "    multi: \"first\n      *second\"\n"
            . "    literal: |\n      *literal\n      &line\n\n      !bang\n"
            . "    folded: >\n      <<: folded\n"
            . "    flow: { k: '&ok', n: 1, l: [x, '*y'] }\n";

        $items = $this->items($this->read($text)->documents[0]);

        self::assertSame([[
            'name'    => '*star',
            'title'   => '&amp',
            'quote'   => "it's *",
            'escaped' => 'say "*hi"',
            'plain'   => 'a&b*c!d',
            'hash'    => 'a#&b',
            'merge'   => '<<',
            'multi'   => 'first *second',
            'literal' => "*literal\n&line\n\n!bang\n",
            'folded'  => "<<: folded\n",
            'flow'    => ['k' => '&ok', 'n' => 1, 'l' => ['x', '*y']],
        ]], $items);
    }

    #[Test]
    public function refusesDuplicateYamlKeysWithTheLine(): void
    {
        $this->assertReadFails("provider: a.one\nprovider: a.two\n", 'yaml_parse', 2, DocumentFormat::Yaml);
    }

    #[Test]
    public function reportsYamlSyntaxErrorsWithTheLine(): void
    {
        $this->assertReadFails(self::HEADER_YAML . "items:\n  - name: a\n bad: indentation\n", 'yaml_parse', 6, DocumentFormat::Yaml);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function duplicateJsonKeys(): iterable
    {
        yield 'top level' => ['{"provider":"a.one","provider":"a.one","version":1,"scope":"platform","items":[]}'];
        yield 'in an item' => ['{"provider":"a.one","version":1,"scope":"platform","items":[{"a":1,"a":2}]}'];
        yield 'escaped spelling' => ['{"provider":"a.one","version":1,"scope":"platform","items":[{"a":1,"a":2}]}'];
    }

    #[Test]
    #[DataProvider('duplicateJsonKeys')]
    public function refusesDuplicateJsonKeys(string $json): void
    {
        $this->assertReadFails($json, 'duplicate_key', null, DocumentFormat::Json);
    }

    #[Test]
    public function refusesInvalidJson(): void
    {
        $this->assertReadFails('{"provider":', 'json_parse', null, DocumentFormat::Json);
    }

    #[Test]
    public function capsTheJsonDepth(): void
    {
        $limits = new ReaderLimits(maxDepth: 4);
        $deep   = str_repeat('[', 7) . str_repeat(']', 7);
        $ok     = str_repeat('[', 5) . str_repeat(']', 5);

        $this->assertReadFails('{"provider":"a.one","version":1,"scope":"platform","items":[' . $deep . ']}', 'json_parse', null, DocumentFormat::Json, $limits);
        self::assertSame([[[[[[]]]]]], $this->items($this->read('{"provider":"a.one","version":1,"scope":"platform","items":[' . $ok . ']}', DocumentFormat::Json, $limits)->documents[0]));
    }

    #[Test]
    public function acceptsEverydayYamlThatOnlyLooksLikeTheRefusedFeatures(): void
    {
        // ZBK-v2: the fail-closed scanner still reads ordinary seed files (URLs, times, JSON style, `'it''s: fine'`,
        // multi-line plain and quoted scalars, block scalars, comments).
        $text = self::HEADER_YAML . "meta: { generator: \"seed-kit 0.1\" } # comment\nitems:\n"
            . "  - email: ana@example.com\n"
            . "    url: https://example.com/a?b=1#frag\n"
            . "    times: [10:30, \"11:00\", {at: 12:15}]\n"
            . "    json: {\"k\":\"v\",\"n\":[1,2]}\n"
            . "    note: 'it''s: fine'\n"
            . "    text: Hello! How are you?\n"
            . "    multi: a long\n      plain value\n"
            . "    quoted: \"first\n      second\"\n"
            . "    literal: |\n      *not an alias* !not a tag\n"
            . "    list:\n      - a-b\n      - 'e # f'\n"
            . "  - email: bob@example.com # trailing\n";

        $items = $this->items($this->read($text)->documents[0]);

        self::assertCount(2, $items);
        $item = $items[0];
        self::assertIsArray($item);
        self::assertSame('https://example.com/a?b=1#frag', $item['url']);
        self::assertSame(['10:30', '11:00', ['at' => '12:15']], $item['times']);
        self::assertSame(['k' => 'v', 'n' => [1, 2]], $item['json']);
        self::assertSame("it's: fine", $item['note']);
        self::assertSame('Hello! How are you?', $item['text']);
        self::assertSame('a long plain value', $item['multi']);
        self::assertSame('first second', $item['quoted']);
        self::assertSame("*not an alias* !not a tag\n", $item['literal']);
        self::assertSame(['a-b', 'e # f'], $item['list']);
    }

    #[Test]
    public function capsTheYamlDepthBeforeParsing(): void
    {
        $limits = new ReaderLimits(maxDepth: 4);
        $flow   = static fn (int $n): string => self::HEADER_YAML . 'items: [' . str_repeat('[', $n) . 'x' . str_repeat(']', $n) . "]\n";
        $block  = static fn (int $n): string => self::HEADER_YAML . "items:\n  - " . str_repeat('- ', $n) . "x\n";

        // Bounded like JSON (max_depth + 4) before Symfony's recursive parser runs (ZBK I5).
        $this->assertReadFails($flow(40), 'too_deep', 4);
        $this->assertReadFails($flow(7), 'too_deep', 4, null, $limits);
        $this->assertReadFails($block(7), 'too_deep', 5, null, $limits);
        self::assertSame([[[[[['x']]]]]], $this->items($this->read($flow(5), null, $limits)->documents[0]));
        self::assertSame([[[[['x']]]]], $this->items($this->read($block(4), null, $limits)->documents[0]));
    }

    #[Test]
    public function acceptsAnItemAtTheMaximumDepthInBlockStyle(): void
    {
        // Item depth 16 (the default max_depth): the pre-scanner's estimate must not refuse it.
        $item = "  - k0:\n";
        for ($level = 1; $level < 15; ++$level) {
            $item .= str_repeat(' ', 4 + 2 * $level) . 'k' . $level . ":\n";
        }
        $item .= str_repeat(' ', 34) . "- x\n";

        self::assertCount(1, $this->items($this->read(self::HEADER_YAML . "items:\n" . $item)->documents[0]));
    }

    #[Test]
    public function refusesNulAndInvalidUtf8BuiltByTheParser(): void
    {
        $this->assertReadFails(self::HEADER_YAML . "items:\n  - { email: \"a\\0b\" }\n", 'encoding');
        $this->assertReadFails(self::HEADER_YAML . "items:\n  - { \"e\\0\": a }\n", 'encoding');
        $this->assertReadFails('{"provider":"a.one","version":1,"scope":"platform","items":[{"email":"a\\u0000b"}]}', 'encoding', null, DocumentFormat::Json);
    }

    #[Test]
    public function keepsGiantIntegersAsStringsAndReadsInfinities(): void
    {
        $json = $this->read('{"provider":"a.one","version":1,"scope":"platform","items":[{"big":123456789012345678901234567890,"inf":1e999,"zero":-0}]}');
        $yaml = $this->read(self::HEADER_YAML . "items:\n  - { big: 123456789012345678901234567890, inf: .inf, ninf: -.inf }\n");

        $jsonItem = $this->items($json->documents[0])[0];
        self::assertIsArray($jsonItem);
        self::assertSame('123456789012345678901234567890', $jsonItem['big']);
        self::assertTrue(is_float($jsonItem['inf']) && is_infinite($jsonItem['inf']) && $jsonItem['inf'] > 0);
        self::assertSame(0, $jsonItem['zero']);
        $yamlItem = $this->items($yaml->documents[0])[0];
        self::assertIsArray($yamlItem);
        self::assertSame('123456789012345678901234567890', $yamlItem['big']);
        self::assertTrue(is_float($yamlItem['inf']) && is_infinite($yamlItem['inf']) && $yamlItem['inf'] > 0);
        self::assertTrue(is_float($yamlItem['ninf']) && is_infinite($yamlItem['ninf']) && $yamlItem['ninf'] < 0);
    }

    #[Test]
    public function handlesAOneMegabyteSingleLine(): void
    {
        $value = str_repeat('a', 1048576);
        $long  = str_repeat('b', 65536);

        $json = $this->items($this->read('{"provider":"a.one","version":1,"scope":"platform","items":[{"name":"' . $value . '"}]}')->documents[0]);
        $yaml = $this->items($this->read(self::HEADER_YAML . "items:\n  - name: " . $long . "\n")->documents[0]);

        self::assertSame([['name' => $value]], $json);
        self::assertSame([['name' => $long]], $yaml);
        // The YAML parser gives up on a 1 MiB plain scalar (PCRE backtrack limit, no line): refused, never a crash.
        $this->assertReadFails(self::HEADER_YAML . "items:\n  - name: " . $value . "\n", 'yaml_parse', null, DocumentFormat::Yaml);
    }

    #[Test]
    public function stripsAUtf8ByteOrderMarkButHashesTheRawBytes(): void
    {
        $yaml = "\xEF\xBB\xBF" . self::HEADER_YAML . "items: []\n";
        $json = "\xEF\xBB\xBF" . '{"provider":"a.one","version":1,"scope":"platform","items":[]}';

        $set = $this->read($yaml);

        self::assertSame('crm.contacts', $set->documents[0]->header->provider->value);
        self::assertSame('sha256:' . hash('sha256', $yaml), $set->hash->value);
        self::assertSame(DocumentFormat::Json, $this->read($json)->format);
        self::assertSame('a.one', $this->read($json)->documents[0]->header->provider->value);
    }

    /**
     * @return iterable<string, array{string, DocumentFormat|null}>
     */
    public static function badEncodings(): iterable
    {
        yield 'utf-16 le bom' => ["\xFF\xFEp\x00r\x00", null];
        yield 'utf-16 be bom' => ["\xFE\xFF\x00p\x00r", DocumentFormat::Yaml];
        yield 'utf-32 be bom' => ["\x00\x00\xFE\xFF\x00\x00\x00p", DocumentFormat::Json];
        yield 'nul byte' => [self::HEADER_YAML . "items: [\"a\0b\"]\n", null];
        yield 'invalid utf-8' => [self::HEADER_YAML . "items: [\"\xC3\x28\"]\n", null];
        yield 'invalid utf-8 in json' => ['{"provider":"a.one","version":1,"scope":"platform","items":["' . "\xFF" . '"]}', null];
    }

    #[Test]
    #[DataProvider('badEncodings')]
    public function refusesBadEncodings(string $text, ?DocumentFormat $format): void
    {
        $this->assertReadFails($text, 'encoding', null, $format);
    }

    #[Test]
    public function countsTheBytesWhileReading(): void
    {
        $text = self::HEADER_YAML . "items: []\n";

        $this->assertReadFails($text, 'too_large', null, null, new ReaderLimits(maxBytes: strlen($text) - 1));
        self::assertCount(1, $this->read($text, null, new ReaderLimits(maxBytes: strlen($text)))->documents);
        $this->assertReadFails(self::HEADER_YAML . 'items: ["' . str_repeat('x', 200000) . "\"]\n", 'too_large', null, null, new ReaderLimits(maxBytes: 100000, maxTreeBytes: 100000));
    }

    #[Test]
    public function capsTheTreeFormatsButNotJsonLines(): void
    {
        $limits = new ReaderLimits(maxTreeBytes: 20);

        $this->assertReadFails(self::HEADER_YAML . "items: []\n", 'too_large_for_tree', null, null, $limits);
        $this->assertReadFails('{"provider":"a.one","version":1,"scope":"platform","items":[]}', 'too_large_for_tree', null, DocumentFormat::Json, $limits);
        self::assertSame([['a' => 1]], $this->items($this->read(self::HEADER_JSON . "\n{\"a\":1}\n", null, $limits)->documents[0]));
    }

    #[Test]
    public function capsTheItemsOfATreeDocument(): void
    {
        $limits = new ReaderLimits(maxItems: 2);

        $this->assertReadFails(self::HEADER_YAML . "items: [1, 2, 3]\n", 'too_many_items', null, null, $limits);
        self::assertSame([1, 2], $this->items($this->read(self::HEADER_YAML . "items: [1, 2]\n", null, $limits)->documents[0]));
    }

    #[Test]
    public function refusesAnUnreadableInput(): void
    {
        stream_wrapper_register(FailingReadStream::SCHEME, FailingReadStream::class);

        try {
            $input = fopen(FailingReadStream::SCHEME . '://input', 'r');
            self::assertIsResource($input);
            (new DocumentReader())->read($input, null, new ReaderLimits());
            self::fail('An unreadable input must be refused.');
        } catch (DocumentReadException $e) {
            self::assertSame('unreadable', $e->reason);
            self::assertNull($e->lineNumber);
        } finally {
            stream_wrapper_unregister(FailingReadStream::SCHEME);
        }
    }

    #[Test]
    public function refusesWhenTheTempBufferCannotBeOpened(): void
    {
        $input    = $this->stream(self::HEADER_YAML . "items: []\n");
        $warnings = [];
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', RefusingOpenStream::class);
        // Restored in the finally block below; swallows the fopen() warning of the refused temp stream.
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            (new DocumentReader())->read($input, null, new ReaderLimits());
            self::fail('The read must fail without a temp buffer.');
        } catch (DocumentReadException $e) {
            self::assertSame('unreadable', $e->reason);
        } finally {
            restore_error_handler();
            stream_wrapper_restore('php');
        }
        self::assertCount(1, $warnings);
        self::assertStringContainsString('php://temp/maxmemory:2097152', $warnings[0]);
    }

    #[Test]
    public function readsJsonLinesLazilyAndAgainOnEveryIteration(): void
    {
        $text = self::HEADER_JSON . "\r\n{\"email\":\"a\"}\r\n\r\n   \n{\"email\":\"b\"}\n{bad json}\n";

        $set = $this->read($text);

        self::assertSame(DocumentFormat::JsonLines, $set->format);
        self::assertSame('sha256:' . hash('sha256', $text), $set->hash->value);
        self::assertSame('crm.contacts', $set->documents[0]->header->provider->value);
        for ($pass = 0; $pass < 2; ++$pass) {
            [$seen, $error] = $this->itemsUntilError($set->documents[0]);

            self::assertSame([['email' => 'a'], ['email' => 'b']], $seen);
            self::assertSame('json_parse', $error?->reason);
            self::assertSame(6, $error->lineNumber);
        }
    }

    #[Test]
    public function readsAHeaderOnlyJsonLinesFile(): void
    {
        $set = $this->read(self::HEADER_JSON, DocumentFormat::JsonLines);

        self::assertSame('crm.contacts', $set->documents[0]->header->provider->value);
        self::assertSame([], $this->items($set->documents[0]));
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function brokenJsonLinesItems(): iterable
    {
        yield 'duplicate key' => [self::HEADER_JSON . "\n{\"a\":1}\n{\"a\":1,\"a\":2}\n", 'duplicate_key', 3];
        yield 'invalid utf-8' => [self::HEADER_JSON . "\n\n{\"a\":\"\xC3\x28\"}\n", 'encoding', 3];
        yield 'nul byte' => [self::HEADER_JSON . "\n{\"a\":\"\0\"}\n", 'encoding', 2];
        yield 'line too long' => [self::HEADER_JSON . "\n{\"a\":\"" . str_repeat('x', 100) . "\"}\n", 'line_too_long', 2];
        yield 'last line too long without a line break' => [self::HEADER_JSON . "\n{\"a\":1}\n{\"a\":\"" . str_repeat('x', 100) . '"}', 'line_too_long', 3];
    }

    #[Test]
    #[DataProvider('brokenJsonLinesItems')]
    public function reportsBrokenJsonLinesItemsWithTheirLine(string $text, string $reason, int $line): void
    {
        $set = $this->read($text, DocumentFormat::JsonLines, new ReaderLimits(maxLineBytes: 80));

        try {
            $this->items($set->documents[0]);
            self::fail('The broken line must be reported.');
        } catch (DocumentReadException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($line, $e->lineNumber);
        }
    }

    #[Test]
    public function aJsonLinesLineMayUseTheWholeCap(): void
    {
        $item = '{"a":"' . str_repeat('x', 72) . '"}';
        self::assertSame(80, strlen($item));

        $set = $this->read(self::HEADER_JSON . "\n" . $item . "\n" . $item, DocumentFormat::JsonLines, new ReaderLimits(maxLineBytes: 80));

        self::assertCount(2, $this->items($set->documents[0]));
    }

    #[Test]
    public function capsTheJsonLinesItemsAfterYieldingTheAllowedOnes(): void
    {
        $set            = $this->read(self::HEADER_JSON . "\n1\n2\n\n3\n", DocumentFormat::JsonLines, new ReaderLimits(maxItems: 2));
        [$seen, $error] = $this->itemsUntilError($set->documents[0]);

        self::assertSame([1, 2], $seen);
        self::assertSame('too_many_items', $error?->reason);
        self::assertNull($error->lineNumber);
    }

    /**
     * @return iterable<string, array{string, string, int|null}>
     */
    public static function brokenJsonLinesHeaders(): iterable
    {
        yield 'empty file' => ['', 'header_invalid', 1];
        yield 'header line too long' => ['{"provider":"crm.contacts","version":1,"scope":"organization","meta":{"generator":"' . str_repeat('g', 60) . "\"}}\n{}\n", 'line_too_long', 1];
        yield 'items in the header' => ['{"provider":"a.one","version":1,"scope":"platform","items":[]}' . "\n{}\n", 'unknown_key', null];
        yield 'header not json' => ["{provider}\n{}\n", 'json_parse', null];
        yield 'header not an object' => ["[1]\n", 'not_a_document', null];
        yield 'header with a utf-16 bom' => ["\xFF\xFE{}\n", 'encoding', null];
        yield 'header missing the version' => ['{"provider":"a.one","scope":"platform"}', 'header_invalid', null];
    }

    #[Test]
    #[DataProvider('brokenJsonLinesHeaders')]
    public function refusesBrokenJsonLinesHeadersOnRead(string $text, string $reason, ?int $line): void
    {
        $this->assertReadFails($text, $reason, $line, DocumentFormat::JsonLines, new ReaderLimits(maxLineBytes: 120));
    }

    #[Test]
    public function jsonLinesHeaderMayStartWithAUtf8Bom(): void
    {
        $set = $this->read("\xEF\xBB\xBF" . self::HEADER_JSON . "\n{\"a\":1}\n");

        self::assertSame(DocumentFormat::JsonLines, $set->format);
        self::assertSame([['a' => 1]], $this->items($set->documents[0]));
    }

    #[Test]
    public function streamsManyJsonLinesWithFlatMemory(): void
    {
        $input = tmpfile();
        self::assertIsResource($input);
        fwrite($input, self::HEADER_JSON . "\n");
        $line = '{"email":"user-%06d@example.com","name":"' . str_repeat('n', 60) . '"}' . "\n";
        for ($i = 0; $i < 60000; ++$i) {
            fwrite($input, sprintf($line, $i));
        }
        rewind($input);
        $set = (new DocumentReader())->read($input, null, new ReaderLimits(maxBytes: 16777216, maxTreeBytes: 1024, maxItems: 60000, maxLineBytes: 256));
        fclose($input);
        gc_collect_cycles();

        $base  = memory_get_usage();
        $peak  = 0;
        $count = 0;
        $last  = null;
        foreach ($set->documents[0]->items() as $item) {
            ++$count;
            $last = $item;
            if ($count % 1000 === 0) {
                $peak = max($peak, memory_get_usage() - $base);
            }
        }

        self::assertSame(60000, $count);
        self::assertSame(['email' => 'user-059999@example.com', 'name' => str_repeat('n', 60)], $last);
        self::assertLessThan(1048576, $peak, 'Items are read from the temp stream, not kept in memory.');
    }

    /**
     * @return iterable<string, array{string, DocumentFormat}>
     */
    public static function guesses(): iterable
    {
        yield 'yaml' => ["provider: a\n", DocumentFormat::Yaml];
        yield 'yaml comment' => ["# {\"a\":1}\n", DocumentFormat::Yaml];
        yield 'json list is not guessed as json' => ['[1]', DocumentFormat::Yaml];
        yield 'json' => ['{"a":1}', DocumentFormat::Json];
        yield 'pretty json' => ["{\n  \"a\": 1\n}\n", DocumentFormat::Json];
        yield 'json after whitespace and bom' => ["\xEF\xBB\xBF \r\n\t{\"a\":1}\n", DocumentFormat::Json];
        yield 'json object then a non-object line' => ["{\"a\":1}\n[2]\n", DocumentFormat::Json];
        yield 'json lines' => ["{\"a\":1}\n{\"b\":2}\n", DocumentFormat::JsonLines];
        yield 'json lines with crlf and bom' => ["\xEF\xBB\xBF{\"a\":1} \r\n  {\"b\":2}", DocumentFormat::JsonLines];
        yield 'empty' => ['', DocumentFormat::Yaml];
    }

    #[Test]
    #[DataProvider('guesses')]
    public function guessesTheFormatFromTheFirstBytes(string $head, DocumentFormat $expected): void
    {
        self::assertSame($expected, (new DocumentReader())->guess($head));
    }

    /**
     * @return iterable<string, array{string, DocumentFormat|null}>
     */
    public static function names(): iterable
    {
        yield 'yaml' => ['crm.contacts.organization.20261124-100000.seed.yaml', DocumentFormat::Yaml];
        yield 'yml upper case' => ['SEED.YML', DocumentFormat::Yaml];
        yield 'json' => ['a.seed.json', DocumentFormat::Json];
        yield 'jsonl' => ['a.seed.jsonl', DocumentFormat::JsonLines];
        yield 'ndjson' => ['a.NDJSON', DocumentFormat::JsonLines];
        yield 'no extension' => ['json', null];
        yield 'other extension' => ['a.json.txt', null];
        yield 'extension then a line break' => ["a.json\n", null];
    }

    #[Test]
    #[DataProvider('names')]
    public function knowsTheFormatOfAFileName(string $name, ?DocumentFormat $expected): void
    {
        self::assertSame($expected, DocumentReader::formatOfName($name));
    }

    /**
     * Reads a string.
     */
    private function read(string $text, ?DocumentFormat $format = null, ?ReaderLimits $limits = null): DocumentSet
    {
        return (new DocumentReader())->read($this->stream($text), $format, $limits ?? new ReaderLimits());
    }

    /**
     * Asserts the read (or, for JSON Lines items, nothing: header errors only) fails with the reason and line.
     */
    private function assertReadFails(string $text, string $reason, ?int $line = null, ?DocumentFormat $format = null, ?ReaderLimits $limits = null): void
    {
        try {
            $this->read($text, $format, $limits);
            self::fail(sprintf('Expected the read to fail with "%s".', $reason));
        } catch (DocumentReadException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame($line, $e->lineNumber);
            self::assertSame('SEED_002', $e->errorCode());
            self::assertSame('seed_kit.read.' . $reason, $e->messageKey());
        }
    }

    /**
     * The items of a document as a list.
     *
     * @return list<mixed>
     */
    private function items(SeedDocument $document): array
    {
        $items = [];
        foreach ($document->items() as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * The items read before the first error, and that error (null when the document reads to the end).
     *
     * @return array{list<mixed>, DocumentReadException|null}
     */
    private function itemsUntilError(SeedDocument $document): array
    {
        $items = [];

        try {
            foreach ($document->items() as $item) {
                $items[] = $item;
            }
        } catch (DocumentReadException $e) {
            return [$items, $e];
        }

        return [$items, null];
    }

    /**
     * An in-memory stream holding the text.
     *
     * @return resource
     */
    private function stream(string $text)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}
