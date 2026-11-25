<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;

use function in_array;

/**
 * DDD host scope resolver: the platform and the organizations `acme` and `globex` exist; `globex` is hidden from the
 * actor `bob`.
 */
final readonly class HostScopeResolver implements SeedScopeResolverInterface
{
    public function resolve(SeedScope $requested, ActorRef $actor, Channel $channel): ?SeedScope
    {
        if ($requested->kind->value === ScopeKind::PLATFORM) {
            return $requested;
        }
        $id = $requested->id?->value;
        if (!in_array($id, ['acme', 'globex'], true) || ($id === 'globex' && $actor->value === 'bob')) {
            return null;
        }

        return $requested;
    }
}
