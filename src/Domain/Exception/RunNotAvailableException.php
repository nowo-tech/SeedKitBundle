<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_008: the run is unknown, belongs to another scope or another actor, or its document was discarded or expired
 * (contract section 5.5). Answered as "not found" so run ids cannot be probed.
 */
final class RunNotAvailableException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'The seed run is not available: it does not exist, it is not yours, or its document expired.';

    /**
     * Builds the exception with its static message.
     */
    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_008';
    }
}
