<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * ACCESS_001: the scope resolver does not know the scope or the actor may not see it (the UI answers 404).
 */
final class ScopeNotFoundException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'The scope does not exist or is not visible.';

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
        return 'ACCESS_001';
    }
}
