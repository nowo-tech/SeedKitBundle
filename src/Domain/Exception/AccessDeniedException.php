<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * ACCESS_002: the guard denied the action (export, apply or prune) for the provider in the scope.
 */
final class AccessDeniedException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'Access denied.';

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
        return 'ACCESS_002';
    }
}
