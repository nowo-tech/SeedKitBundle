<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

/**
 * Prune switches of one call (contract section 10.4): prune itself and the two explicit confirmations.
 */
final readonly class PruneOptions
{
    /**
     * Builds the options.
     */
    public function __construct(public bool $prune = false, public bool $allowMassPrune = false, public bool $allowEmptyPrune = false)
    {
    }
}
