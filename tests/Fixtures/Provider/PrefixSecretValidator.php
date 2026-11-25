<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SecretReferenceValidatorInterface;

use function str_starts_with;

/**
 * Fixture validator: `vault:ok-*` is usable and belongs to every scope; anything else is refused.
 */
final readonly class PrefixSecretValidator implements SecretReferenceValidatorInterface
{
    public function isUsable(SecretReference $reference, SeedScope $scope, ActorRef $actor): bool
    {
        return str_starts_with($reference->value, 'vault:ok-');
    }

    public function belongsTo(SecretReference $reference, SeedScope $scope): bool
    {
        return str_starts_with($reference->value, 'vault:ok-');
    }
}
