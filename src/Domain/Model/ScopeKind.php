<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;

/**
 * A scope kind (`platform`, `organization`, `project` or any host kind): `[a-z][a-z0-9_]{0,31}`. Whether the kind is
 * configured is checked by the registry, not here.
 */
final readonly class ScopeKind implements Stringable
{
    /** The kind every host has. */
    public const PLATFORM = 'platform';

    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', $value) !== 1) {
            throw new InvalidValueException('Invalid scope kind.');
        }
    }

    /**
     * Whether both kinds are the same bytes.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The kind.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
