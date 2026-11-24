<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * The subject of `is_granted('SEED_EXPORT' | 'SEED_APPLY' | 'SEED_PRUNE', subject)`: a provider in a scope.
 */
final readonly class SeedPermissionSubject
{
    /**
     * Builds the subject.
     */
    public function __construct(public ProviderName $provider, public SeedScope $scope)
    {
    }
}
