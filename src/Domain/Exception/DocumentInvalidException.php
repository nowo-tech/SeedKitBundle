<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

use Nowo\SeedKitBundle\Domain\Plan\RowError;

use function count;

/**
 * SEED_003: schema or plan-time row errors. Carries the errors (capped at `validation.max_errors`); each names the
 * row index, natural key, JSON Pointer and rule, never the submitted value. Nothing was stored or written.
 */
final class DocumentInvalidException extends SeedKitException
{
    /**
     * @param list<RowError> $errors
     * @param bool $truncated whether more errors existed than the cap
     */
    public function __construct(public readonly array $errors, public readonly bool $truncated = false)
    {
        parent::__construct('The seed document is invalid (' . count($errors) . ($truncated ? '+' : '') . ' error(s)); nothing was written.');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_003';
    }
}
