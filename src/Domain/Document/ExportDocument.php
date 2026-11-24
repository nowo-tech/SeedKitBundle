<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

/**
 * What a document writer renders: the header, the provider schema (field list, notes and property order of the
 * commented template), the mode and the items (export current: prepared rows; export empty: example items rendered as
 * comments). Items are produced lazily and read once.
 */
final readonly class ExportDocument
{
    /**
     * @param iterable<array<string, mixed>> $items
     */
    public function __construct(
        public SeedDocumentHeader $header,
        public SeedSchema $schema,
        public ExportMode $mode,
        public iterable $items,
    ) {
    }
}
