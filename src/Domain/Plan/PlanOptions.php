<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;

/**
 * Options of {@see SeedProviderInterface::planBatch()}: with `lockForUpdate` the
 * provider reads the current rows with its write lock (`SELECT … FOR UPDATE` or a pessimistic lock), in natural-key
 * order, inside the batch transaction the bundle opened.
 */
final readonly class PlanOptions
{
    /**
     * Builds the options.
     */
    public function __construct(public bool $lockForUpdate = false)
    {
    }
}
