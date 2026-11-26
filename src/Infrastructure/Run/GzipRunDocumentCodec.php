<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Run;

use JsonException;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\DocumentSet;
use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Exception\RunNotAvailableException;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Port\RunDocumentCodecInterface;

use function gzdecode;
use function gzencode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function strlen;
use function strpos;
use function substr;

use const JSON_BIGINT_AS_STRING;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The stored form of a UI run's document (contract section 11, Q-SK-5): gzip (level 6) of JSON lines — one line with
 * the format and the raw-bytes hash, then per document one header line (`{"h": …}`) and one line per parsed item
 * (`{"i": …}`). The items are the values the reader produced (JSON-compatible; floats keep their fraction), so planning
 * the decoded set gives the same plan hash as planning the upload. Decoding is capped (`$maxBytes`) and reads items line
 * by line from the decoded text, one at a time. Stateless.
 */
final readonly class GzipRunDocumentCodec implements RunDocumentCodecInterface
{
    /** Version of the stored form. */
    private const VERSION = 1;

    /** JSON flags of a line. */
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param int $maxBytes most bytes a stored document may decode to
     */
    public function __construct(private int $maxBytes = 67108864)
    {
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException when an item is not JSON-compatible (never for a planned document)
     */
    public function encode(DocumentSet $set): string
    {
        $text = json_encode(['v' => self::VERSION, 'format' => $set->format->value, 'hash' => $set->hash->value], self::FLAGS) . "\n";
        foreach ($set->documents as $document) {
            $header = $document->header;
            $text .= json_encode(['h' => [
                'provider'     => $header->provider->value,
                'version'      => $header->version,
                'scope'        => $header->scope->value,
                'schema'       => $header->schemaRef,
                'exportedAt'   => $header->meta->exportedAt,
                'exportedFrom' => $header->meta->exportedFrom?->value,
                'generator'    => $header->meta->generator,
            ]], self::FLAGS) . "\n";
            foreach ($document->items() as $item) {
                $text .= json_encode(['i' => $item], self::FLAGS) . "\n";
            }
        }

        return (string) gzencode($text, 6);
    }

    /**
     * {@inheritDoc}
     */
    public function decode(string $stored): DocumentSet
    {
        $text = @gzdecode($stored, $this->maxBytes);
        if (!is_string($text)) {
            throw new RunNotAvailableException();
        }
        try {
            $offset = 0;
            $first  = $this->line($text, $offset);
            if (($first['v'] ?? null) !== self::VERSION || !is_string($first['format'] ?? null) || !is_string($first['hash'] ?? null)) {
                throw new RunNotAvailableException();
            }
            $format    = DocumentFormat::tryFrom($first['format']) ?? throw new RunNotAvailableException();
            $hash      = new DocumentHash($first['hash']);
            $documents = [];
            $length    = strlen($text);
            while ($offset < $length) {
                $line   = $this->line($text, $offset);
                $header = is_array($line['h'] ?? null) ? $this->header($line['h']) : throw new RunNotAvailableException();
                $start  = $offset;
                while ($offset < $length && substr($text, $offset, 5) === '{"i":') {
                    $offset = $this->next($text, $offset);
                }
                $end         = $offset;
                $documents[] = new SeedDocument($header, function () use ($text, $start, $end): iterable {
                    $at = $start;
                    while ($at < $end) {
                        try {
                            $line = $this->line($text, $at);
                        } catch (JsonException) {
                            throw new RunNotAvailableException();
                        }

                        yield $line['i'] ?? null;
                    }
                });
            }
        } catch (JsonException|InvalidValueException) {
            throw new RunNotAvailableException();
        }

        return new DocumentSet($documents, $format, $hash);
    }

    /**
     * A header from its stored map.
     *
     * @param array<mixed> $header
     *
     * @throws InvalidValueException
     * @throws RunNotAvailableException
     */
    private function header(array $header): SeedDocumentHeader
    {
        if (!is_string($header['provider'] ?? null) || !is_int($header['version'] ?? null) || !is_string($header['scope'] ?? null)) {
            throw new RunNotAvailableException();
        }
        $string = static fn (mixed $value): ?string => is_string($value) ? $value : null;
        $from   = $string($header['exportedFrom'] ?? null);

        return new SeedDocumentHeader(
            new ProviderName($header['provider']),
            $header['version'],
            new ScopeKind($header['scope']),
            $string($header['schema'] ?? null),
            new SeedDocumentMeta($string($header['exportedAt'] ?? null), $from !== null ? new ScopeId($from) : null, $string($header['generator'] ?? null)),
        );
    }

    /**
     * Decodes the line at `$offset` and moves past it.
     *
     * @throws JsonException
     * @throws RunNotAvailableException
     *
     * @return array<mixed>
     */
    private function line(string $text, int &$offset): array
    {
        $next   = $this->next($text, $offset);
        $value  = json_decode(substr($text, $offset, $next - $offset - 1), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        $offset = $next;

        return is_array($value) ? $value : throw new RunNotAvailableException();
    }

    /**
     * The offset after the line at `$offset` (lines end with "\n").
     *
     * @throws RunNotAvailableException
     */
    private function next(string $text, int $offset): int
    {
        $end = strpos($text, "\n", $offset);

        return $end === false ? throw new RunNotAvailableException() : $end + 1;
    }
}
