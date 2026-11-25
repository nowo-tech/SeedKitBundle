<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

/**
 * What a provider wrote for one batch (reported back and checked against the plan counts).
 */
final readonly class BatchResult
{
    /**
     * Builds the result.
     */
    public function __construct(public int $created = 0, public int $updated = 0, public int $deleted = 0)
    {
    }
}
