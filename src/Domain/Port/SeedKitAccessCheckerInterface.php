<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;

/**
 * The house access checker (REQ-UI-002, contract section 12.1). The default maps `security.*_roles`; a host replaces it
 * with `security.access_checker` (e.g. Beacon: platform → developer, organization → manage, project → settings).
 * `$user` is the host's user object or null. Deny by default.
 */
interface SeedKitAccessCheckerInterface
{
    /**
     * Whether the user may use the seed area of the scope at all.
     */
    public function canUseArea(?object $user, SeedScope $scope): bool;

    /**
     * Whether the user may run the action on the provider in the scope.
     */
    public function can(?object $user, SeedAction $action, ProviderName $provider, SeedScope $scope): bool;

    /**
     * The same decision for a stored actor without a security token (Messenger, 0.2). Deny unless implemented.
     */
    public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool;
}
