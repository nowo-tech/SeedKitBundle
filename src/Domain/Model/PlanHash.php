<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;

/**
 * The plan hash (contract section 9): `sk1:` + 64 hex of SHA-256 over the canonical change stream.
 */
final readonly class PlanHash implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\Ask1:[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new InvalidValueException('Invalid plan hash.');
        }
    }

    /**
     * Whether both hashes are equal.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The hash.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
