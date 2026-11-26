<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

use Closure;

use function is_scalar;

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

    /** Makes the fixture providers fail while planning (a host failure in a re-plan). */
    public bool $failPlan = false;

    /** Called by the fixture providers on every plan (tests of what happens meanwhile). */
    public ?Closure $onPlan = null;

    /** Whether planBatch() was asked for locks. */
    public int $lockedPlans = 0;

    /**
     * A stored scalar as text.
     */
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
