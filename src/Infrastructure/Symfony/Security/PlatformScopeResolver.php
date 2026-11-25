<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;

/**
 * The default scope resolver: the platform scope exists, every other scope is unknown. A host with tenants (Beacon:
 * organization = billing account UUID, project = project UUID) sets `scope_resolver` to its own service, which returns
 * null for scopes the actor may not see (QB-SK-1).
 */
final readonly class PlatformScopeResolver implements SeedScopeResolverInterface
{
    /**
     * {@inheritDoc}
     */
    public function resolve(SeedScope $requested, ActorRef $actor, Channel $channel): ?SeedScope
    {
        return $requested->kind->value === ScopeKind::PLATFORM && !$requested->id instanceof ScopeId ? $requested : null;
    }
}
