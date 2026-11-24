<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;

/**
 * The lease per (provider, scope) (`<prefix>seed_lease`): one run applies a provider in a scope at a time. Taken by a
 * conditional update; a crashed holder's lease expires.
 */
interface LeaseStoreInterface
{
    /**
     * Takes the lease; returns 0 when taken, else the seconds left on the holder's lease (≥ 1).
     */
    public function acquire(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): int;

    /**
     * Extends the lease; false when the run no longer holds it.
     */
    public function renew(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): bool;

    /**
     * Releases the lease when the run holds it.
     */
    public function release(ProviderName $provider, SeedScope $scope, RunId $run): void;
}
