<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Writer;

use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Port\DocumentWriterInterface;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Symfony\Component\Yaml\Yaml;

use function array_keys;
use function count;
use function explode;
use function fwrite;
use function implode;
use function is_array;
use function is_string;
use function json_encode;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_replace;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Writes exports item by item (contract section 8): YAML (default; a commented header with the field list, required
 * marks, natural key, personal-data and secret notes; the empty template ends with `items: []` and commented example
 * items), JSON (one object) and JSON Lines (header line + one item per line). Strings that would read back as another
 * type are quoted by the YAML dumper, so an export re-applies with zero changes. Stateless.
 */
final readonly class DocumentWriter implements DocumentWriterInterface
{
    /** JSON flags. */
    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * {@inheritDoc}
     */
    public function write(ExportDocument $document, DocumentFormat $format, $stream): int
    {
        return match ($format) {
            DocumentFormat::Yaml      => $this->yaml($document, $stream),
            DocumentFormat::Json      => $this->json($document, $stream),
            DocumentFormat::JsonLines => $this->jsonLines($document, $stream),
        };
    }

    /**
     * YAML.
     *
     * @param resource $stream
     */
    private function yaml(ExportDocument $document, $stream): int
    {
        $out = [];
        foreach ($this->comments($document) as $comment) {
            $out[] = '# ' . $comment;
        }
        foreach ($this->header($document) as $key => $value) {
            $out[] = rtrim(Yaml::dump([$key => $value], 1, 2));
        }
        fwrite($stream, implode("\n", $out) . "\n");
        $count = 0;
        if ($document->mode === ExportMode::Empty) {
            fwrite($stream, "items: []\n");
            $lines = [];
            foreach ($document->items as $example) {
                $lines[] = $this->yamlItem($example);
            }
            if ($lines !== []) {
                fwrite($stream, "# items:\n" . preg_replace('/^/m', '# ', implode('', $lines)));
            }

            return 0;
        }
        foreach ($document->items as $item) {
            if ($count === 0) {
                fwrite($stream, "items:\n");
            }
            fwrite($stream, $this->yamlItem($item));
            ++$count;
        }
        if ($count === 0) {
            fwrite($stream, "items: []\n");
        }

        return $count;
    }

    /**
     * One YAML list entry, indented under `items:`.
     *
     * @param array<string, mixed> $item
     */
    private function yamlItem(array $item): string
    {
        $dump  = rtrim(Yaml::dump($item, 2, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
        $lines = explode("\n", $dump);
        $out   = '';
        foreach ($lines as $position => $line) {
            $out .= ($position === 0 ? '  - ' : '    ') . $line . "\n";
        }

        return $out;
    }

    /**
     * JSON (pretty, items streamed).
     *
     * @param resource $stream
     */
    private function json(ExportDocument $document, $stream): int
    {
        $header = json_encode($this->header($document), self::JSON | JSON_PRETTY_PRINT);
        fwrite($stream, substr($header, 0, -2) . ",\n    \"items\": [");
        $count = 0;
        if ($document->mode === ExportMode::Current) {
            foreach ($document->items as $item) {
                $encoded = json_encode($item, self::JSON | JSON_PRETTY_PRINT);
                fwrite($stream, ($count === 0 ? "\n" : ",\n") . '        ' . str_replace("\n", "\n        ", $encoded));
                ++$count;
            }
        }
        fwrite($stream, ($count === 0 ? ']' : "\n    ]") . "\n}\n");

        return $count;
    }

    /**
     * JSON Lines.
     *
     * @param resource $stream
     */
    private function jsonLines(ExportDocument $document, $stream): int
    {
        fwrite($stream, json_encode($this->header($document), self::JSON) . "\n");
        $count = 0;
        if ($document->mode === ExportMode::Current) {
            foreach ($document->items as $item) {
                fwrite($stream, json_encode($item, self::JSON) . "\n");
                ++$count;
            }
        }

        return $count;
    }

    /**
     * The top-level keys except `items`.
     *
     * @return array<string, mixed>
     */
    private function header(ExportDocument $document): array
    {
        $header = $document->header;
        $meta   = [];
        if ($header->meta->exportedAt !== null) {
            $meta['exportedAt'] = $header->meta->exportedAt;
        }
        if ($header->meta->exportedFrom !== null) {
            $meta['exportedFrom'] = $header->meta->exportedFrom->value;
        }
        if ($header->meta->generator !== null) {
            $meta['generator'] = $header->meta->generator;
        }
        $out = [];
        if ($header->schemaRef !== null) {
            $out['$schema'] = $header->schemaRef;
        }
        $out['provider'] = $header->provider->value;
        $out['version']  = $header->version;
        $out['scope']    = $header->scope->value;
        if ($meta !== []) {
            $out['meta'] = $meta;
        }

        return $out;
    }

    /**
     * The YAML header comments: schema hint, title, natural key, notes, field list.
     *
     * @return list<string>
     */
    private function comments(ExportDocument $document): array
    {
        $header   = $document->header;
        $schema   = $document->schema;
        $lines    = [];
        if ($header->schemaRef !== null) {
            $lines[] = 'yaml-language-server: $schema=' . $header->schemaRef;
        }
        $lines[] = sprintf('SeedKit document: %s (provider %s, version %d, scope %s)', $this->clean($schema->title($header->provider->value)), $header->provider->value, $header->version, $header->scope->value);
        $normalised = [];
        foreach ($schema->keyNormalisers() as $property => $mode) {
            $normalised[] = $property . ' ' . ($mode === 'lower' ? 'lower-cased' : 'NFC');
        }
        $lines[] = sprintf(
            'Natural key: %s%s. Personal data: %s. Secrets: %s.',
            implode(', ', $schema->keyProperties()),
            $normalised === [] ? '' : ' (' . implode(', ', $normalised) . ')',
            $this->listOrNone($schema->personalDataProperties()),
            $schema->secretProperties() === [] ? 'none' : implode(', ', $schema->secretProperties()) . ' (vault: references only; omitted when not of this scope; absent keeps the current value)',
        );
        if ($schema->readonlyProperties() !== []) {
            $lines[] = 'Read-only (ignored on import): ' . implode(', ', $schema->readonlyProperties()) . '.';
        }
        $lines[] = 'Fields (* required):';
        foreach (array_keys($schema->properties()) as $property) {
            $lines[] = '  ' . $this->field($schema, $property);
        }

        return $lines;
    }

    /**
     * One field line: name, required mark, type and description.
     */
    private function field(SeedSchema $schema, string $property): string
    {
        $definition = $schema->properties()[$property];
        $type       = $definition['type'] ?? null;
        $type       = is_array($type) ? implode('|', $this->strings($type)) : (is_string($type) ? $type : 'any');
        $format     = is_string($definition['format'] ?? null) ? ', ' . $definition['format'] : '';
        $refs       = $schema->references();
        $ref        = isset($refs[$property]) ? ', key of ' . $refs[$property] : '';
        $text       = $this->clean($schema->description($property));

        return $property . ($schema->isRequired($property) ? '*' : '') . ' (' . $type . $format . $ref . ')' . ($text !== '' ? ': ' . $text : '');
    }

    /**
     * A comma list, or "none".
     *
     * @param list<string> $values
     */
    private function listOrNone(array $values): string
    {
        return count($values) === 0 ? 'none' : implode(', ', $values);
    }

    /**
     * The strings of a list.
     *
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private function strings(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Comment-safe text: one line, no control characters.
     */
    private function clean(string $text): string
    {
        return (string) preg_replace('/[\p{Cc}\p{Cf}\x{2028}\x{2029}]+/u', ' ', $text);
    }
}
