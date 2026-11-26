<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;

/**
 * Optional host port for UI mounts whose path carries no `{scopeId}` (e.g. `/organization/seeds` for "the
 * organization I am working in"): the id of the actor's current scope of that kind, or null. The id is then resolved
 * and checked by the scope resolver like a route id (unknown or invisible ⇒ 404).
 */
interface SeedCurrentScopeInterface
{
    /**
     * The actor's current scope id of the kind, or null.
     */
    public function currentId(ScopeKind $kind, ActorRef $actor): ?ScopeId;
}
