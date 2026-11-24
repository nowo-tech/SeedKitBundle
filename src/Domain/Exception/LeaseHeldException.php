<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * CONFLICT_001: another run holds the lease of a (provider, scope) of this run (contract section 10.3). Nothing was
 * written; retry after {@see $retryAfterSeconds}.
 */
final class LeaseHeldException extends SeedKitException
{
    /**
     * @param int $retryAfterSeconds seconds left on the other run's lease (at least 1)
     */
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Another seed run is applying the same provider in this scope. Retry later.');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'CONFLICT_001';
    }
}
