<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;

/**
 * Natural keys that earlier documents of the same run will create (contract section 6): a contacts document may
 * reference a company the companies document of the same file creates. Per run, never shared.
 */
final class PendingKeys
{
    /** @var array<string, NaturalKeySet> */
    private array $byProvider = [];

    /**
     * Records a key the run will create for a provider.
     */
    public function add(ProviderName $provider, NaturalKey $key): void
    {
        ($this->byProvider[$provider->value] ??= new NaturalKeySet())->add($key);
    }

    /**
     * Whether the run will create the key for the provider.
     */
    public function has(ProviderName $provider, NaturalKey $key): bool
    {
        return isset($this->byProvider[$provider->value]) && $this->byProvider[$provider->value]->has($key);
    }
}
