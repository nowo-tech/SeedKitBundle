<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;

/**
 * The access decision the application layer asks before every export, plan and apply (re-checked per call). The
 * Symfony implementation is the guard: checker, then the `nowo_seed_kit.access_check` event; console granted by
 * default with listeners able to deny.
 */
interface SeedAccessGateInterface
{
    /**
     * Whether the actor may run the action on the provider in the scope through the channel.
     */
    public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool;
}
