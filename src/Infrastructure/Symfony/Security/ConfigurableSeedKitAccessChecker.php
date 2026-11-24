<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_intersect;

/**
 * The default checker (REQ-UI-002, Q-SK-7): the area needs one of `security.access_roles`; then `export_roles`,
 * `apply_roles`, `prune_roles` (default `[]`: nobody) per action, overridable per provider
 * (`security.providers.<name>`). Role hierarchy applied. Anything that is not a Symfony user is denied, and so is
 * every stored actor (`canForActor()`: a host implements it for Messenger in 0.2). Deny by default.
 */
final readonly class ConfigurableSeedKitAccessChecker implements SeedKitAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles
     * @param array{export: list<string>, apply: list<string>, prune: list<string>} $actionRoles
     * @param array<string, array{export: list<string>|null, apply: list<string>|null, prune: list<string>|null}> $providerRoles
     */
    public function __construct(
        private array $accessRoles,
        private array $actionRoles,
        private array $providerRoles = [],
        private ?RoleHierarchyInterface $roleHierarchy = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function canUseArea(?object $user, SeedScope $scope): bool
    {
        return $this->holds($user, $this->accessRoles);
    }

    /**
     * {@inheritDoc}
     */
    public function can(?object $user, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        $roles = $this->providerRoles[$provider->value][$action->value] ?? $this->actionRoles[$action->value];

        return $this->holds($user, $this->accessRoles) && $this->holds($user, $roles);
    }

    /**
     * {@inheritDoc}
     */
    public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        return false;
    }

    /**
     * Whether the user holds one of the roles.
     *
     * @param list<string> $roles
     */
    private function holds(?object $user, array $roles): bool
    {
        if (!$user instanceof UserInterface || $roles === []) {
            return false;
        }
        $held = $user->getRoles();
        if ($this->roleHierarchy instanceof RoleHierarchyInterface) {
            $held = $this->roleHierarchy->getReachableRoleNames($held);
        }

        return array_intersect($held, $roles) !== [];
    }
}
