<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_011: a provider returned keys it was not asked for, rows out of natural-key order, ids, or a plain value in a secret field (fail closed). A bug of the host provider, caught before any write.
 */
final class ProviderContractException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'A seed provider broke its contract (wrong keys, order or secret values); nothing was written by the bundle.';

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
        return 'SEED_011';
    }
}
