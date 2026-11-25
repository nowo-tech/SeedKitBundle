<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Event;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * Dispatched after a dry run planned without errors (nothing written).
 */
final readonly class SeedRunPlannedEvent
{
    /**
     * @param list<string> $providers
     */
    public function __construct(
        public array $providers,
        public SeedScope $scope,
        public PlanHash $planHash,
        public int $changes,
        public ActorRef $actor,
    ) {
    }
}
