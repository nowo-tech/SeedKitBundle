<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * Decides about secret references (contract section 13.2, QB-SK-2). Without a configured validator every secret
 * reference is refused on import and omitted on export (fail closed).
 */
interface SecretReferenceValidatorInterface
{
    /**
     * Import: the reference exists, belongs to the target scope and the actor may use it.
     */
    public function isUsable(SecretReference $reference, SeedScope $scope, ActorRef $actor): bool;

    /**
     * Export: the reference resolves to a secret of the scope (otherwise the field is omitted).
     */
    public function belongsTo(SecretReference $reference, SeedScope $scope): bool;
}
