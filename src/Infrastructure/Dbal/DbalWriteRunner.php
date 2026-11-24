<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitInterface;

/**
 * A transaction-doctrine write unit (replay after a deadlock, lock-wait timeout or serialization failure; 3 attempts,
 * full jitter; `ConcurrencyConflict` after the last one, `committed: true` when an attempt had committed) around one
 * DBAL transaction on the bundle's connection (`DoctrineTransactionRunner::forConnection()`: savepoint-aware nesting,
 * leftover levels rolled back). Inside a host transaction the unit runs once, as a nested level. No entity manager is
 * touched (the unit's registry holds none, {@see ConnectionOnlyRegistry}). The work receives the attempt number, so
 * the applier can let the provider forget its state before a replay.
 */
final readonly class DbalWriteRunner implements WriteRunnerInterface
{
    /**
     * @param WriteUnitInterface $writeUnit the bundle's write unit
     * @param TransactionRunnerInterface $transactions `DoctrineTransactionRunner::forConnection()` on the same connection
     */
    public function __construct(private WriteUnitInterface $writeUnit, private TransactionRunnerInterface $transactions)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function write(callable $work): mixed
    {
        $attempt = 0;

        return $this->writeUnit->run(function () use (&$attempt, $work): mixed {
            $current = ++$attempt;

            return $this->transactions->run(static fn (): mixed => $work($current));
        });
    }
}
