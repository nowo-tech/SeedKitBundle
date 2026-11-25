<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;

/**
 * A write runner for tests: runs the work once (attempt 1), without a transaction. Use the bundle's real runner in
 * database tests.
 */
final readonly class DirectWriteRunner implements WriteRunnerInterface
{
    /**
     * {@inheritDoc}
     */
    public function write(callable $work): mixed
    {
        return $work(1);
    }
}
