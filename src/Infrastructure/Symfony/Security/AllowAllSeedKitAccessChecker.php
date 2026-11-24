<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;

/**
 * Grants everything. Demos only: selectable as `security.access_checker` only together with
 * `security.allow_unauthenticated: true` (the configuration refuses it otherwise).
 */
final readonly class AllowAllSeedKitAccessChecker implements SeedKitAccessCheckerInterface
{
    /**
     * {@inheritDoc}
     */
    public function canUseArea(?object $user, SeedScope $scope): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function can(?object $user, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        return true;
    }
}
