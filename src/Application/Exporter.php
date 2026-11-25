<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Generator;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportDocument;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentHeader;
use Nowo\SeedKitBundle\Domain\Document\SeedDocumentMeta;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Exception\ProviderContractException;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Port\DocumentWriterInterface;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Psr\Clock\ClockInterface;

use function array_key_exists;
use function array_keys;
use function count;
use function strcmp;

use const DATE_ATOM;

/**
 * Exports one provider (contract section 8 / 13.2): the empty template or the current rows the actor may read, keys in
 * schema property order with read-only properties last, rows in natural-key byte order (checked, never re-sorted),
 * secrets written as `vault:` references only when the validator confirms they belong to the scope, else omitted. A
 * provider that returns plain text in a secret property, a property outside the schema, a row whose key does not match
 * its data, or rows out of order fails closed ({@see ProviderContractException}). Stateless.
 */
final readonly class Exporter
{
    /**
     * Builds the exporter.
     */
    public function __construct(
        private DocumentWriterInterface $writer,
        private SeedKitSettings $settings,
        private ClockInterface $clock,
        private ?SecretReferenceValidatorInterface $secrets = null,
    ) {
    }

    /**
     * Writes the export to the stream; returns the number of items.
     *
     * @param resource $stream
     *
     * @throws ProviderContractException
     */
    public function export(SeedProviderInterface $provider, SeedContext $context, ExportMode $mode, DocumentFormat $format, $stream): int
    {
        $schema = $provider->schema();
        $header = new SeedDocumentHeader(
            $provider->name(),
            $provider->version(),
            $context->scope->kind,
            $provider->name()->value . '.v' . $provider->version() . '.schema.json',
            new SeedDocumentMeta(
                $mode === ExportMode::Current ? $this->clock->now()->format(DATE_ATOM) : null,
                $mode === ExportMode::Current ? $context->scope->id : null,
                $this->settings->generator,
            ),
        );
        $items = $mode === ExportMode::Empty ? $provider->exportEmpty($context) : $this->current($provider, $schema, $context);

        return $this->writer->write(new ExportDocument($header, $schema, $mode, $items), $format, $stream);
    }

    /**
     * The prepared current rows.
     *
     * @throws ProviderContractException
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function current(SeedProviderInterface $provider, SeedSchema $schema, SeedContext $context): Generator
    {
        $previous   = null;
        $properties = array_keys($schema->properties());
        $keys       = $schema->keyProperties();
        foreach ($provider->exportCurrent($context) as $item) {
            if (!$item instanceof SeedItem || ($previous !== null && strcmp($previous, $item->key->canonical) >= 0)) {
                throw new ProviderContractException();
            }
            $previous = $item->key->canonical;
            if (count($keys) !== count($item->key->components)) {
                throw new ProviderContractException();
            }
            foreach ($keys as $position => $property) {
                if (($item->data[$property] ?? null) !== $item->key->components[$position]) {
                    throw new ProviderContractException();
                }
            }
            foreach (array_keys($item->data) as $property) {
                if (!array_key_exists($property, $schema->properties())) {
                    throw new ProviderContractException();
                }
            }
            yield $this->row($schema, $properties, $item, $context);
        }
    }

    /**
     * One row: writable properties in schema order, then read-only ones; secrets as checked references or omitted.
     *
     * @param list<string> $properties
     *
     * @throws ProviderContractException
     *
     * @return array<string, mixed>
     */
    private function row(SeedSchema $schema, array $properties, SeedItem $item, SeedContext $context): array
    {
        $row      = [];
        $readonly = [];
        foreach ($properties as $property) {
            if (!array_key_exists($property, $item->data)) {
                continue;
            }
            $value = $item->data[$property];
            if ($schema->isSecret($property)) {
                if ($value === null) {
                    continue;
                }
                if (!$value instanceof SecretReference) {
                    throw new ProviderContractException();
                }
                if (!$this->secrets instanceof SecretReferenceValidatorInterface || !$this->secrets->belongsTo($value, $context->scope)) {
                    continue;
                }
                $value = $value->value;
            }
            if ($schema->isReadonly($property)) {
                $readonly[$property] = $value;
            } else {
                $row[$property] = $value;
            }
        }

        return $row + $readonly;
    }
}
