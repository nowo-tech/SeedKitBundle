<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;

/**
 * Resolves the requested target scope for an actor (host port, QB-SK-1): returns the scope when it exists and the
 * actor may see it, null otherwise (the bundle answers "not found", never "forbidden", so ids cannot be probed).
 */
interface SeedScopeResolverInterface
{
    /**
     * The visible scope, or null.
     */
    public function resolve(SeedScope $requested, ActorRef $actor, Channel $channel): ?SeedScope;
}
