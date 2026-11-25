<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Closure;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;

/**
 * Test seam of the DB-backed fixture providers: a callback run at the start of every applyBatch() (inside the batch
 * transaction), used by the race tests to act on a second connection while a batch is open.
 */
final class HostHooks
{
    /** @var (Closure(string, AppliedBatch): void)|null */
    public ?Closure $beforeApply = null;

    /**
     * Runs the callback, if any.
     */
    public function fire(string $provider, AppliedBatch $batch): void
    {
        if ($this->beforeApply instanceof Closure) {
            ($this->beforeApply)($provider, $batch);
        }
    }
}
