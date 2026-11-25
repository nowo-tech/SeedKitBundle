<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

/**
 * Runs one write unit on the bundle's connection (contract section 10.3): one transaction, replayed after a lock
 * conflict (deadlock, lock wait timeout, serialization failure), a `ConcurrencyConflict` of transaction-contract after
 * the last attempt. Inside a host transaction the unit runs once as a savepoint.
 */
interface WriteRunnerInterface
{
    /**
     * Runs `$work` in a write unit; `$work` receives the 1-based attempt number and re-reads what it changes.
     *
     * @template T
     *
     * @param callable(int): T $work
     *
     * @return T
     */
    public function write(callable $work): mixed;
}
