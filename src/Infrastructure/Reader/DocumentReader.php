<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Reader;

use JsonException;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Exception\DocumentReadException;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function array_is_list;
use function array_key_exists;
use function count;
use function fclose;
use function feof;
use function fgets;
use function fopen;
use function fread;
use function fwrite;
use function hash_final;
use function hash_init;
use function hash_update;
use function in_array;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function ltrim;
use function max;
use function preg_replace;
use function preg_match;
use function preg_split;
use function rewind;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function trim;

use const JSON_BIGINT_AS_STRING;
use const JSON_THROW_ON_ERROR;

/**
 * Reads a seed file into a {@see DocumentSet} (contract sections 8 and 13.3). The input is copied once into a private
 * temp stream while bytes are counted (never trusted from headers) and hashed; then:
 *
 * - YAML / JSON (whole tree, at most `max_tree_bytes`): encoding checks (UTF-8, BOM stripped, UTF-16/32 and NUL
 *   refused), {@see YamlPreScanner} / {@see JsonDuplicateKeyScanner}, parse without objects, constants, custom tags or
 *   dates; a multi-document YAML stream (`---`) or `{"documents": [...]}` is a run with several providers;
 * - JSON Lines (`.jsonl`, the only streamed format): line 1 is the header, every next line one item, each line at most
 *   `max_line_bytes`; items are read from the temp stream on demand, so the file never sits in memory.
 *
 * Only the keys `$schema`, `provider`, `version`, `scope`, `meta`, `items` are accepted at the top level. Stateless:
 * the temp stream belongs to the returned set.
 */
final readonly class DocumentReader
{
    /** Allowed top-level keys. */
    private const TOP_KEYS = ['$schema', 'provider', 'version', 'scope', 'meta', 'items'];

    /** Allowed meta keys. */
    private const META_KEYS = ['exportedAt', 'exportedFrom', 'generator'];

    /**
     * Reads a stream (a file handle or stdin).
     *
     * @param resource $input
     * @param DocumentFormat|null $format null: guessed from the content
     *
     * @throws DocumentReadException
     */
    public function read($input, ?DocumentFormat $format, ReaderLimits $limits): DocumentSet
    {
        $buffer = fopen('php://temp/maxmemory:2097152', 'w+b');
        if (!is_resource($buffer)) {
            throw new DocumentReadException('unreadable');
        }
        $hash  = hash_init('sha256');
        $bytes = 0;
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if ($chunk === false) {
                throw new DocumentReadException('unreadable');
            }
            $bytes += strlen($chunk);
            if ($bytes > $limits->maxBytes) {
                fclose($buffer);

                throw new DocumentReadException('too_large');
            }
            hash_update($hash, $chunk);
            fwrite($buffer, $chunk);
        }
        $documentHash = new DocumentHash('sha256:' . hash_final($hash));
        rewind($buffer);
        $head   = (string) fread($buffer, 4096);
        $format ??= $this->guess($head);
        if ($format === DocumentFormat::JsonLines) {
            return new DocumentSet([$this->jsonLines($buffer, $limits)], $format, $documentHash);
        }
        if ($bytes > $limits->maxTreeBytes) {
            fclose($buffer);

            throw new DocumentReadException('too_large_for_tree');
        }
        rewind($buffer);
        $text = (string) stream_get_contents($buffer);
        fclose($buffer);
        $text = $this->checkEncoding($text);

        $trees = $format === DocumentFormat::Yaml ? $this->yaml($text, $limits) : $this->json($text, $limits);
        if (count($trees) > $limits->maxDocuments) {
            throw new DocumentReadException('too_many_documents');
        }
        $documents = [];
        foreach ($trees as $tree) {
            $header = $this->header($tree, true);
            $items  = $tree['items'];
            if (!is_array($items) || !array_is_list($items)) {
                throw new DocumentReadException('items_not_a_list');
            }
            if (count($items) > $limits->maxItems) {
                throw new DocumentReadException('too_many_items');
            }
            $documents[] = new SeedDocument($header, static fn (): array => $items);
        }

        return new DocumentSet($documents, $format, $documentHash);
    }

    /**
     * The format from the first bytes: `{` with a second line that starts an object ⇒ JSON Lines, `{` or `[` ⇒ JSON,
     * else YAML.
     */
    public function guess(string $head): DocumentFormat
    {
        $trimmed = ltrim($head, "\xEF\xBB\xBF \t\r\n");
        if (!str_starts_with($trimmed, '{')) {
            return DocumentFormat::Yaml;
        }
        $lines = preg_split("/\r\n|\n/", trim($trimmed));
        if ($lines !== false && count($lines) > 1 && str_starts_with($lines[0], '{') && str_ends_with(rtrim($lines[0]), '}') && str_starts_with(ltrim($lines[1]), '{')) {
            return DocumentFormat::JsonLines;
        }

        return DocumentFormat::Json;
    }

    /**
     * The format of a file name (`.yaml`, `.yml`, `.json`, `.jsonl`, `.ndjson`), or null.
     */
    public static function formatOfName(string $name): ?DocumentFormat
    {
        return match (true) {
            preg_match('/\.(ya?ml)\z/i', $name) === 1    => DocumentFormat::Yaml,
            preg_match('/\.(jsonl|ndjson)\z/i', $name) === 1 => DocumentFormat::JsonLines,
            preg_match('/\.json\z/i', $name) === 1       => DocumentFormat::Json,
            default                                      => null,
        };
    }

    /**
     * Strips a UTF-8 BOM; refuses other BOMs, NUL bytes and invalid UTF-8.
     *
     * @throws DocumentReadException
     */
    private function checkEncoding(string $text): string
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        } elseif (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF") || str_starts_with($text, "\x00\x00\xFE\xFF")) {
            throw new DocumentReadException('encoding');
        }
        if (str_contains($text, "\0") || preg_match('//u', $text) !== 1) {
            throw new DocumentReadException('encoding');
        }

        return $text;
    }

    /**
     * The YAML documents of a stream.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DocumentReadException
     */
    private function yaml(string $text, ReaderLimits $limits): array
    {
        YamlPreScanner::assertSafe($text);
        $parts = preg_split('/^(?:---|\.\.\.)[ \t]*(?:#.*)?$/m', $text);
        $trees = [];
        foreach ($parts === false ? [] : $parts as $part) {
            if (trim((string) preg_replace('/^\s*#.*$/m', '', $part)) === '') {
                continue;
            }
            if (count($trees) >= $limits->maxDocuments) {
                throw new DocumentReadException('too_many_documents');
            }
            try {
                $tree = Yaml::parse($part, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
            } catch (ParseException $e) {
                throw new DocumentReadException('yaml_parse', $e->getParsedLine() > 0 ? $e->getParsedLine() : null);
            }
            $trees[] = $this->map($tree);
        }

        return $trees;
    }

    /**
     * The JSON document(s): one document object, or `{"documents": [...]}`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws DocumentReadException
     */
    private function json(string $text, ReaderLimits $limits): array
    {
        $tree = $this->decode($text, $limits);
        if (is_array($tree) && array_key_exists('documents', $tree)) {
            if (count($tree) !== 1 || !is_array($tree['documents']) || !array_is_list($tree['documents'])) {
                throw new DocumentReadException('unknown_key');
            }
            $trees = [];
            foreach ($tree['documents'] as $document) {
                $trees[] = $this->map($document);
            }

            return $trees;
        }

        return [$this->map($tree)];
    }

    /**
     * Decodes JSON text with duplicate keys refused.
     *
     * @throws DocumentReadException
     */
    private function decode(string $text, ReaderLimits $limits): mixed
    {
        try {
            $value = json_decode($text, true, max(1, $limits->maxDepth + 4), JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new DocumentReadException('json_parse');
        }
        JsonDuplicateKeyScanner::assertUnique($text);

        return $value;
    }

    /**
     * The single JSON Lines document: header now, items on demand.
     *
     * @param resource $buffer
     *
     * @throws DocumentReadException
     */
    private function jsonLines($buffer, ReaderLimits $limits): SeedDocument
    {
        rewind($buffer);
        $first = $this->line($buffer, $limits, 1);
        if ($first === null) {
            throw new DocumentReadException('header_invalid', 1);
        }
        $header = $this->header($this->map($this->decode($this->checkEncoding($first), $limits)), false);

        return new SeedDocument($header, function () use ($buffer, $limits): iterable {
            rewind($buffer);
            $this->line($buffer, $limits, 1);
            $number = 1;
            $items  = 0;
            while (($line = $this->line($buffer, $limits, ++$number)) !== null) {
                if (trim($line) === '') {
                    continue;
                }
                if (++$items > $limits->maxItems) {
                    throw new DocumentReadException('too_many_items');
                }
                try {
                    $item = $this->decode($this->checkEncoding($line), $limits);
                } catch (DocumentReadException $e) {
                    throw new DocumentReadException($e->reason, $number);
                }

                yield $item;
            }
        });
    }

    /**
     * The next line of the buffer (without the line break), or null at the end.
     *
     * @param resource $buffer
     *
     * @throws DocumentReadException when the line is longer than `max_line_bytes`
     */
    private function line($buffer, ReaderLimits $limits, int $number): ?string
    {
        $line = fgets($buffer, max(1, $limits->maxLineBytes + 2));
        if ($line === false) {
            return null;
        }
        if (strlen($line) > $limits->maxLineBytes && !str_ends_with($line, "\n")) {
            throw new DocumentReadException('line_too_long', $number);
        }

        return rtrim($line, "\r\n");
    }

    /**
     * A JSON object / YAML mapping with string keys.
     *
     * @return array<string, mixed>
     *
     * @throws DocumentReadException
     */
    private function map(mixed $tree): array
    {
        if (!is_array($tree) || ($tree !== [] && array_is_list($tree))) {
            throw new DocumentReadException('not_a_document');
        }
        $map = [];
        foreach ($tree as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }

    /**
     * The checked header of a document tree.
     *
     * @param array<string, mixed> $tree
     *
     * @throws DocumentReadException
     */
    private function header(array $tree, bool $withItems): SeedDocumentHeader
    {
        foreach ($tree as $key => $value) {
            if (!in_array($key, self::TOP_KEYS, true) || ($key === 'items' && !$withItems)) {
                throw new DocumentReadException('unknown_key');
            }
        }
        $meta = $tree['meta'] ?? [];
        if (!is_array($meta) || ($meta !== [] && array_is_list($meta))) {
            throw new DocumentReadException('header_invalid');
        }
        foreach ($meta as $key => $value) {
            if (!in_array($key, self::META_KEYS, true) || !is_string($value)) {
                throw new DocumentReadException('header_invalid');
            }
        }
        $provider = $tree['provider'] ?? null;
        $version  = $tree['version'] ?? null;
        $scope    = $tree['scope'] ?? null;
        $schema   = $tree['$schema'] ?? null;
        if (!is_string($provider) || !is_int($version) || !is_string($scope) || ($schema !== null && (!is_string($schema) || strlen($schema) > 255)) || ($withItems && !array_key_exists('items', $tree))) {
            throw new DocumentReadException('header_invalid');
        }
        try {
            $from      = $meta['exportedFrom'] ?? null;
            $generator = $meta['generator'] ?? null;
            if (is_string($generator) && strlen($generator) > 64) {
                throw new DocumentReadException('header_invalid');
            }

            return new SeedDocumentHeader(
                new ProviderName($provider),
                $version,
                new ScopeKind($scope),
                $schema,
                new SeedDocumentMeta($meta['exportedAt'] ?? null, is_string($from) ? new ScopeId($from) : null, $generator),
            );
        } catch (InvalidValueException) {
            throw new DocumentReadException('header_invalid');
        }
    }
}
