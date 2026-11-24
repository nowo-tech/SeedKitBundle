<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Event;

use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * Dispatched after an export was written (value objects and counts only).
 */
final readonly class SeedExportedEvent
{
    /**
     * Builds the event.
     */
    public function __construct(
        public ProviderName $provider,
        public SeedScope $scope,
        public ExportMode $mode,
        public int $rows,
        public ActorRef $actor,
    ) {
    }
}
