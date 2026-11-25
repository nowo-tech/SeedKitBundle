<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;

/**
 * Leases in memory for tests (per test instance; no expiry).
 */
final class InMemoryLeaseStore implements LeaseStoreInterface
{
    /** @var array<string, string> lease key → run id */
    private array $holders = [];

    /**
     * {@inheritDoc}
     */
    public function acquire(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): int
    {
        $key    = $provider->value . '|' . $scope->key();
        $holder = $this->holders[$key] ?? null;
        if ($holder !== null && $holder !== $run->value) {
            return $ttlSeconds;
        }
        $this->holders[$key] = $run->value;

        return 0;
    }

    /**
     * {@inheritDoc}
     */
    public function renew(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): bool
    {
        return ($this->holders[$provider->value . '|' . $scope->key()] ?? null) === $run->value;
    }

    /**
     * {@inheritDoc}
     */
    public function release(ProviderName $provider, SeedScope $scope, RunId $run): void
    {
        $key = $provider->value . '|' . $scope->key();
        if (($this->holders[$key] ?? null) === $run->value) {
            unset($this->holders[$key]);
        }
    }
}
