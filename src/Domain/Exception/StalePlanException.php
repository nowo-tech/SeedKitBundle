<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_005: the re-plan hash differs from the reviewed plan hash (`--expect-hash`), nothing was written.
 */
final class StalePlanException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'The data changed since the reviewed plan; review the new diff and apply again.';

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
        return 'SEED_005';
    }
}
