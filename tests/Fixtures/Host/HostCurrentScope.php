<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Port\SeedCurrentScopeInterface;

/**
 * The host's "current organization": `acme` for everyone but `nomad` (no current organization).
 */
final readonly class HostCurrentScope implements SeedCurrentScopeInterface
{
    public function currentId(ScopeKind $kind, ActorRef $actor): ?ScopeId
    {
        return $actor->value === 'nomad' ? null : new ScopeId('acme');
    }
}
