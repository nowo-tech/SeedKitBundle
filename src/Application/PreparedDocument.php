<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Document\SeedDocument;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

/**
 * A document matched to its provider and schema (header checked). Per run.
 */
final readonly class PreparedDocument
{
    /**
     * Builds the pair.
     */
    public function __construct(public SeedDocument $document, public SeedProviderInterface $provider, public SeedSchema $schema)
    {
    }
}
