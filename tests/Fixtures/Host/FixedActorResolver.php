<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;

/**
 * DDD host actor resolver: the operator of the bounded context (no security token involved).
 */
final readonly class FixedActorResolver implements SeedActorResolverInterface
{
    public const ACTOR = 'ddd-operator';

    public function current(): ActorRef
    {
        return new ActorRef(self::ACTOR);
    }
}
