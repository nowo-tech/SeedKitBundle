<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

use Nowo\SeedKitBundle\Domain\Plan\RowError;

/**
 * SEED_004: a secret field holds a plain value, or a reference the secret-reference validator refuses (or no
 * validator is configured). Carries every row error of the document (secret or not); the value is never echoed.
 * Nothing was stored or written.
 */
final class SecretValueRefusedException extends SeedKitException
{
    /** Static message. */
    public const MESSAGE = 'A secret field holds a plain value or a reference that cannot be used here; only vault references are accepted.';

    /**
     * @param list<RowError> $errors
     */
    public function __construct(public readonly array $errors = [], public readonly bool $truncated = false)
    {
        parent::__construct(self::MESSAGE);
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_004';
    }
}
