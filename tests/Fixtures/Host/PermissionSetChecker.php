<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * DDD host access checker over permission sets: a user (or stored actor) holds `<action>:<provider>` permissions.
 */
final readonly class PermissionSetChecker implements SeedKitAccessCheckerInterface
{
    /**
     * @param array<string, list<string>> $permissions identifier => permissions (`export:host.tags`, `area`)
     */
    public function __construct(private array $permissions = [])
    {
    }

    public function canUseArea(?object $user, SeedScope $scope): bool
    {
        return $this->holds($user instanceof UserInterface ? $user->getUserIdentifier() : null, 'area');
    }

    public function can(?object $user, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        return $this->holds($user instanceof UserInterface ? $user->getUserIdentifier() : null, $action->value . ':' . $provider->value);
    }

    public function canForActor(ActorRef $actor, SeedAction $action, ProviderName $provider, SeedScope $scope): bool
    {
        return $this->holds($actor->value, $action->value . ':' . $provider->value);
    }

    private function holds(?string $identifier, string $permission): bool
    {
        return $identifier !== null && in_array($permission, $this->permissions[$identifier] ?? [], true);
    }
}
