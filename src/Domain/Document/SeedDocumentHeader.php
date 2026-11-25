<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Document;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;

/**
 * The header of one document: provider, document version (1–999), scope kind (a kind only, Q-SK-12), the informational
 * `$schema` reference and the meta block.
 */
final readonly class SeedDocumentHeader
{
    /**
     * @throws InvalidValueException when the version is out of range
     */
    public function __construct(
        public ProviderName $provider,
        public int $version,
        public ScopeKind $scope,
        public ?string $schemaRef = null,
        public SeedDocumentMeta $meta = new SeedDocumentMeta(),
    ) {
        if ($version < 1 || $version > 999) {
            throw new InvalidValueException('A document version is between 1 and 999.');
        }
    }
}
