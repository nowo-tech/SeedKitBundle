<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

/**
 * The id of a scope (organization UUID, project id…): 1–128 bytes of exact text ({@see ExactText}). Opaque to the
 * bundle and compared with `===`; the host's scope resolver decides whether it exists and is visible.
 */
final readonly class ScopeId implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        ExactText::assert($value, 128, 'Invalid scope id.');
    }

    /**
     * Whether both ids are the same bytes.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The id.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
