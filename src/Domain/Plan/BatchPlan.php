<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

/**
 * What a provider returns for one slice: exactly one change per requested item (same order) and the domain errors it
 * found (any error makes the whole run invalid before the first write).
 */
final readonly class BatchPlan
{
    /**
     * @param list<RowChange> $changes
     * @param list<RowError> $errors
     */
    public function __construct(public array $changes, public array $errors = [])
    {
    }
}
