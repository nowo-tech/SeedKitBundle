<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;

/**
 * The current actor (default: the security user identifier), or null without an authenticated user.
 */
interface SeedActorResolverInterface
{
    /**
     * The current actor.
     */
    public function current(): ?ActorRef;
}
