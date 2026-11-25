<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_007: the document scope kind differs from the target scope kind, or the provider is not available in that kind.
 */
final class ScopeMismatchException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'The document scope kind does not match the target scope.';

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
        return 'SEED_007';
    }
}
