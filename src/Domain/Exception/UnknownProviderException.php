<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_001: the provider name or document version is not registered, or the provider is not available in the scope kind.
 */
final class UnknownProviderException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'The seed provider is not registered or not available for this scope kind.';

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
        return 'SEED_001';
    }
}
