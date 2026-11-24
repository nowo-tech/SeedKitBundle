<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Vault;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;
use Nowo\VaultBundle\Event\VaultAccessAction;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Nowo\VaultBundle\ValueObject\VaultUserRef;
use Nowo\VaultBundle\View\VaultItemView;

use function preg_match;
use function str_replace;

/**
 * `vault:<item-uuid>` references checked against VaultBundle (QB-SK-2, `secrets.vault_bridge.enabled`): the item
 * exists, is not in the trash and is owned by the target scope (`scope_format`, default `{kind}:{id}`); on import the
 * actor must also be allowed to view it (the actor ref is the Vault user id). The console `system` actor is checked on
 * scope ownership only (fresh installs have no user; the console is the operator's channel, QB-SK-5).
 */
final readonly class VaultSecretReferenceValidator implements SecretReferenceValidatorInterface
{
    /**
     * Builds the validator.
     */
    public function __construct(
        private VaultItemQueryInterface $items,
        private VaultAccessCheckInterface $access,
        private string $scopeFormat = '{kind}:{id}',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isUsable(SecretReference $reference, SeedScope $scope, ActorRef $actor): bool
    {
        if (!$this->belongsTo($reference, $scope)) {
            return false;
        }

        return $actor->isSystem() || $this->access->canAccessItem(VaultUserRef::ofId($actor->value), $reference->target(), VaultAccessAction::View);
    }

    /**
     * {@inheritDoc}
     */
    public function belongsTo(SecretReference $reference, SeedScope $scope): bool
    {
        $id = $reference->target();
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $id) !== 1) {
            return false;
        }
        $item = $this->items->findById($id);

        return $item instanceof VaultItemView && !$item->isTrashed() && $item->ownerScope !== null && $item->ownerScope === $this->ownerScope($scope);
    }

    /**
     * The Vault owner scope of a seed scope.
     */
    private function ownerScope(SeedScope $scope): string
    {
        if ($scope->id === null) {
            return $scope->kind->value;
        }

        return str_replace(['{kind}', '{id}'], [$scope->kind->value, $scope->id->value], $this->scopeFormat);
    }
}
