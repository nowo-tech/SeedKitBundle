<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

/**
 * Rows of the fixture providers, by provider and canonical key (the "host database" of the unit tests).
 */
final class InMemoryStore
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $rows = [];

    /** Calls of applyBatch(). */
    public int $applyCalls = 0;

    /** Calls of afterBatch(). */
    public int $afterBatchCalls = 0;

    /** Whether planBatch() was asked for locks. */
    public int $lockedPlans = 0;
}
