<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function hash;
use function preg_match;

/**
 * `sha256:` + 64 hex of the raw document bytes (audit, duplicate upload detection).
 */
final readonly class DocumentHash implements Stringable
{
    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (preg_match('/\Asha256:[0-9a-f]{64}\z/D', $value) !== 1) {
            throw new InvalidValueException('Invalid document hash.');
        }
    }

    /**
     * The hash of raw bytes.
     */
    public static function ofBytes(string $bytes): self
    {
        return new self('sha256:' . hash('sha256', $bytes));
    }

    /**
     * The hash.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
