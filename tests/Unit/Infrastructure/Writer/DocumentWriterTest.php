<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Infrastructure\Writer;

use Generator;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Writer\DocumentWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function fopen;
use function fwrite;
use function rewind;
use function stream_get_contents;

/**
 * The export writer (contract section 8): YAML template and current exports with the commented header, JSON and JSON
 * Lines, and round-trip stability through the reader.
 */
final class DocumentWriterTest extends TestCase
{
    private const RICH_COMMENTS = <<<'YAML'
        # yaml-language-server: $schema=crm.contacts.v1.schema.json
        # SeedKit document: CRM contacts list (provider crm.contacts, version 1, scope organization)
        # Natural key: email (email lower-cased, name NFC). Personal data: email, name. Secrets: apiToken (vault: references only; omitted when not of this scope; absent keeps the current value).
        # Read-only (ignored on import): createdAt.
        # Fields (* required):
        #   email* (string, email): E-mail address
        #   name* (string|null)
        #   company (string, key of crm.companies)
        #   apiToken (string)
        #   tags (array)
        #   any (any)
        #   createdAt (string): Set by the app
        $schema: crm.contacts.v1.schema.json
        provider: crm.contacts
        version: 1
        scope: organization
        meta: { exportedAt: '2026-11-24T10:00:00+00:00', exportedFrom: org-1, generator: 'seed-kit 0.1.0' }

        YAML;

    #[Test]
    public function writesTheCommentedYamlTemplateWithExampleItems(): void
    {
        [$count, $output] = $this->write(ExportMode::Empty, DocumentFormat::Yaml, self::examples());

        self::assertSame(0, $count);
        self::assertSame(self::RICH_COMMENTS . <<<'YAML'
            items: []
            # items:
            #   - email: ana@example.com
            #     name: Ana
            #     tags:
            #       - customer
            #   - email: luis@example.com
            #     tags: []

            YAML, $output);
    }

    #[Test]
    public function writesTheCurrentYamlExport(): void
    {
        [$count, $output] = $this->write(ExportMode::Current, DocumentFormat::Yaml, self::examples());

        self::assertSame(2, $count);
        self::assertSame(self::RICH_COMMENTS . <<<'YAML'
            items:
              - email: ana@example.com
                name: Ana
                tags:
                  - customer
              - email: luis@example.com
                tags: []

            YAML, $output);
    }

    #[Test]
    public function writesTheMinimalYamlHeaderAndAnEmptyItemList(): void
    {
        $expected = <<<'YAML'
            # SeedKit document: demo.tags (provider demo.tags, version 3, scope platform)
            # Natural key: slug. Personal data: none. Secrets: none.
            # Fields (* required):
            #   slug (string)
            provider: demo.tags
            version: 3
            scope: platform
            items: []

            YAML;

        foreach ([ExportMode::Empty, ExportMode::Current] as $mode) {
            [$count, $output] = $this->write($mode, DocumentFormat::Yaml, [], self::bareHeader(), self::bareSchema());

            self::assertSame(0, $count);
            self::assertSame($expected, $output, $mode->value);
        }
    }

    #[Test]
    public function writesJson(): void
    {
        [$count, $output] = $this->write(ExportMode::Current, DocumentFormat::Json, self::examples());

        self::assertSame(2, $count);
        self::assertSame(<<<'JSON'
            {
                "$schema": "crm.contacts.v1.schema.json",
                "provider": "crm.contacts",
                "version": 1,
                "scope": "organization",
                "meta": {
                    "exportedAt": "2026-11-24T10:00:00+00:00",
                    "exportedFrom": "org-1",
                    "generator": "seed-kit 0.1.0"
                },
                "items": [
                    {
                        "email": "ana@example.com",
                        "name": "Ana",
                        "tags": [
                            "customer"
                        ]
                    },
                    {
                        "email": "luis@example.com",
                        "tags": []
                    }
                ]
            }

            JSON, $output);
    }

    #[Test]
    public function writesJsonWithoutItemsForATemplateOrAnEmptyExport(): void
    {
        $expected = <<<'JSON'
            {
                "provider": "demo.tags",
                "version": 3,
                "scope": "platform",
                "items": []
            }

            JSON;

        [$count, $output] = $this->write(ExportMode::Empty, DocumentFormat::Json, [['slug' => 'never-written']], self::bareHeader(), self::bareSchema());
        self::assertSame(0, $count);
        self::assertSame($expected, $output);
        [$count, $output] = $this->write(ExportMode::Current, DocumentFormat::Json, [], self::bareHeader(), self::bareSchema());
        self::assertSame(0, $count);
        self::assertSame($expected, $output);
    }

    #[Test]
    public function writesJsonLines(): void
    {
        [$count, $output] = $this->write(ExportMode::Current, DocumentFormat::JsonLines, [['slug' => 'a/ñ'], ['slug' => 'b']], self::bareHeader(), self::bareSchema());

        self::assertSame(2, $count);
        self::assertSame("{\"provider\":\"demo.tags\",\"version\":3,\"scope\":\"platform\"}\n{\"slug\":\"a/ñ\"}\n{\"slug\":\"b\"}\n", $output);
        [$count, $output] = $this->write(ExportMode::Empty, DocumentFormat::JsonLines, [['slug' => 'never-written']], self::bareHeader(), self::bareSchema());
        self::assertSame(0, $count);
        self::assertSame("{\"provider\":\"demo.tags\",\"version\":3,\"scope\":\"platform\"}\n", $output);
    }

    #[Test]
    public function cleansControlCharactersFromCommentText(): void
    {
        $schema = new SeedSchema([
            'title'      => "Bad\r\ntitle\u{2029}\u{200B}\x07here",
            'x-seed-key' => ['slug'],
            'properties' => ['slug' => ['type' => 'string', 'description' => "line one\nprovider: evil\u{2028}x"]],
        ]);

        [, $output] = $this->write(ExportMode::Empty, DocumentFormat::Yaml, [], self::bareHeader(), $schema);

        self::assertStringContainsString("# SeedKit document: Bad title here (provider demo.tags, version 3, scope platform)\n", $output);
        self::assertStringContainsString("#   slug (string): line one provider: evil x\n", $output);
        self::assertStringNotContainsString("\nprovider: evil", $output);
    }

    #[Test]
    public function streamsTheItemsOnce(): void
    {
        $items = (static function (): Generator {
            for ($i = 0; $i < 3; ++$i) {
                yield ['slug' => 's' . $i];
            }
        })();

        [$count] = $this->write(ExportMode::Current, DocumentFormat::JsonLines, $items, self::bareHeader(), self::bareSchema());

        self::assertSame(3, $count);
        self::assertFalse($items->valid(), 'The generator is consumed in one pass.');
    }

    /**
     * @return iterable<string, array{DocumentFormat}>
     */
    public static function formats(): iterable
    {
        foreach (DocumentFormat::cases() as $format) {
            yield $format->value => [$format];
        }
    }

    #[Test]
    #[DataProvider('formats')]
    public function aCurrentExportReadsBackIdentical(DocumentFormat $format): void
    {
        $items = [
            [
                'date'      => '2026-11-24',
                'datetime'  => '2026-11-24T10:00:00+00:00',
                'int'       => '123',
                'octal'     => '0o17',
                'hex'       => '0x1A',
                'exp'       => '1e3',
                'negzero'   => '-0',
                'float'     => '1.5',
                'bool'      => 'true',
                'yes'       => 'yes',
                'null'      => 'null',
                'tilde'     => '~',
                'empty'     => '',
                'space'     => ' leading',
                'alias'     => '*star',
                'anchor'    => '&amp',
                'tag'       => '!bang',
                'directive' => '%YAML',
                'merge'     => '<<',
                'comment'   => '# hash',
                'colon'     => 'a: b',
                'dash'      => '- x',
                'pipe'      => '| x',
                'gt'        => '> x',
                'at'        => '@a',
                'backtick'  => '`a',
                'multiline' => "line 1\nline 2",
                'tab'       => "a\tb",
                'unicode'   => 'Ñandú 🦤',
                'marker'    => '---',
                'realInt'   => 42,
                'realFloat' => 1.5,
                'realBool'  => false,
                'realNull'  => null,
                'list'      => ['*x', '2026-01-01', 7],
                'emptyList' => [],
                'map'       => ['k' => '&v', 'n' => ['deep' => '1']],
            ],
            ['date' => '2026-11-25', 'list' => [['k' => '*v']]],
        ];
        $header = self::richHeader();

        [$count, $output] = $this->write(ExportMode::Current, $format, $items, $header);

        self::assertSame(2, $count);
        $set = (new DocumentReader())->read(self::stream($output), DocumentReader::formatOfName('export.' . $format->extension()), new ReaderLimits());
        self::assertSame($format, $set->format);
        self::assertCount(1, $set->documents);
        $read = $set->documents[0];
        self::assertEquals($header, $read->header);
        $back = [];
        foreach ($read->items() as $item) {
            $back[] = $item;
        }
        self::assertSame($items, $back);
    }

    #[Test]
    public function integralFloatsKeepTheirTypeInYamlOnly(): void
    {
        [, $yaml]  = $this->write(ExportMode::Current, DocumentFormat::Yaml, [['slug'      => 'a', 'price' => 10.0]], self::bareHeader(), self::bareSchema());
        [, $jsonl] = $this->write(ExportMode::Current, DocumentFormat::JsonLines, [['slug' => 'a', 'price' => 10.0]], self::bareHeader(), self::bareSchema());

        self::assertStringEndsWith("items:\n  - slug: a\n    price: 10.0\n", $yaml);
        // JSON drops the zero fraction; the canonical comparison of the planner treats 10 and 10.0 alike.
        self::assertStringEndsWith("\n{\"slug\":\"a\",\"price\":10}\n", $jsonl);
    }

    #[Test]
    #[DataProvider('formats')]
    public function anEmptyExportReadsBackWithNoItems(DocumentFormat $format): void
    {
        [, $output] = $this->write(ExportMode::Empty, $format, self::examples());

        $set = (new DocumentReader())->read(self::stream($output), $format, new ReaderLimits());

        self::assertEquals(self::richHeader(), $set->documents[0]->header);
        $back = [];
        foreach ($set->documents[0]->items() as $item) {
            $back[] = $item;
        }
        self::assertSame([], $back);
    }

    /**
     * Writes and returns the count and output.
     *
     * @param iterable<array<string, mixed>> $items
     *
     * @return array{int, string}
     */
    private function write(ExportMode $mode, DocumentFormat $format, iterable $items, ?SeedDocumentHeader $header = null, ?SeedSchema $schema = null): array
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        $count = (new DocumentWriter())->write(new ExportDocument($header ?? self::richHeader(), $schema ?? self::richSchema(), $mode, $items), $format, $stream);
        rewind($stream);

        return [$count, (string) stream_get_contents($stream)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function examples(): array
    {
        return [['email' => 'ana@example.com', 'name' => 'Ana', 'tags' => ['customer']], ['email' => 'luis@example.com', 'tags' => []]];
    }

    private static function richHeader(): SeedDocumentHeader
    {
        return new SeedDocumentHeader(
            new ProviderName('crm.contacts'),
            1,
            new ScopeKind('organization'),
            'crm.contacts.v1.schema.json',
            new SeedDocumentMeta('2026-11-24T10:00:00+00:00', new ScopeId('org-1'), 'seed-kit 0.1.0'),
        );
    }

    private static function richSchema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => "CRM\ncontacts\u{2028}list",
            'type'                 => 'object',
            'x-seed-key'           => ['email'],
            'x-seed-key-normalise' => ['email' => 'lower', 'name' => 'nfc'],
            'x-seed-ref'           => ['company' => 'crm.companies'],
            'required'             => ['email', 'name'],
            'properties'           => [
                'email'     => ['type' => 'string', 'format' => 'email', 'description' => "E-mail\taddress", 'x-seed-personal-data' => true],
                'name'      => ['type' => ['string', 'null', 5], 'x-seed-personal-data' => true],
                'company'   => ['type' => 'string'],
                'apiToken'  => ['type' => 'string', 'x-seed-secret' => true],
                'tags'      => ['type' => 'array', 'format' => 7],
                'any'       => [],
                'createdAt' => ['type' => 'string', 'x-seed-readonly' => true, 'description' => 'Set by the app'],
            ],
        ]);
    }

    private static function bareHeader(): SeedDocumentHeader
    {
        return new SeedDocumentHeader(new ProviderName('demo.tags'), 3, new ScopeKind('platform'));
    }

    private static function bareSchema(): SeedSchema
    {
        return new SeedSchema(['title' => '', 'x-seed-key' => ['slug'], 'properties' => ['slug' => ['type' => 'string']]]);
    }

    /**
     * @return resource
     */
    private static function stream(string $text)
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $text);
        rewind($stream);

        return $stream;
    }
}
