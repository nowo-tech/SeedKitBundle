<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;
use function strlen;

/**
 * A provider name (`crm.contacts`, `navigation.menus`): 1–4 dot-separated segments of `[a-z][a-z0-9_]{0,31}`, at most
 * 64 bytes. Compared exactly.
 */
final readonly class ProviderName implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (strlen($value) > 64 || preg_match('/\A[a-z][a-z0-9_]{0,31}(\.[a-z][a-z0-9_]{0,31}){0,3}\z/D', $value) !== 1) {
            throw new InvalidValueException('Invalid provider name.');
        }
    }

    /**
     * Whether both names are the same bytes.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * The name.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
