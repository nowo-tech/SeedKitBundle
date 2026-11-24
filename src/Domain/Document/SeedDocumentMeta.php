<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Nowo\SeedKitBundle\Domain\Model\ScopeId;

/**
 * The informational `meta` block of a document: never used to select a target (Q-SK-12); the diff warns when
 * `exportedFrom` differs from the target scope id.
 */
final readonly class SeedDocumentMeta
{
    /**
     * @param string|null $exportedAt ISO 8601 instant written by the exporter
     * @param ScopeId|null $exportedFrom the scope id the export was taken from
     * @param string|null $generator `seed-kit <version>` or the tool that wrote the file (≤ 64 bytes)
     */
    public function __construct(
        public ?string $exportedAt = null,
        public ?ScopeId $exportedFrom = null,
        public ?string $generator = null,
    ) {
    }
}
