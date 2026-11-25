<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;

/**
 * Grants every action (tests that are not about access).
 */
final readonly class GrantAllGate implements SeedAccessGateInterface
{
    /**
     * {@inheritDoc}
     */
    public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool
    {
        return true;
    }
}
