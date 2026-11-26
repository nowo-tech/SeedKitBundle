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
 *
 * Contract (ZCE-I1): `currentId()` MUST derive the id from server-side state of the authenticated actor (session or user record), never
 * from request input (query, header, body). It MAY return null; it MUST NOT throw for an unknown actor. The bundle treats
 * the result as untrusted input: it is resolved by `SeedScopeResolverInterface` (unknown or invisible ⇒ 404) and checked
 * by `canUseArea()` (⇒ 403), exactly like a `{scopeId}` from the URL. Runs stay bound to the scope they were planned in,
 * so a change of current scope makes earlier run URLs answer 404. It is called once per request and must be stateless
 * (worker mode).
 */
interface SeedCurrentScopeInterface
{
    /**
     * The actor's current scope id of the kind, or null.
     */
    public function currentId(ScopeKind $kind, ActorRef $actor): ?ScopeId;
}
