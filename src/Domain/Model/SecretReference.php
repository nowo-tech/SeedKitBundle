<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

use function preg_match;
use function substr;

/**
 * A pointer to a secret held elsewhere (contract section 13.2): `vault:` followed by
 * `[A-Za-z0-9][A-Za-z0-9._:/#-]{0,199}`. Opaque to the bundle; the secret-reference validator port decides whether it
 * exists and may be used by the actor in the target scope (QB-SK-2: in Beacon, `vault:<item-uuid>`).
 */
final readonly class SecretReference implements Stringable
{
    /** The scheme prefix. */
    public const PREFIX = 'vault:';

    /**
     * @param string $value the full reference, `vault:` included
     *
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        if (!self::isReference($value)) {
            throw new InvalidValueException('Invalid secret reference.');
        }
    }

    /**
     * Whether `$value` has the reference syntax.
     */
    public static function isReference(string $value): bool
    {
        return preg_match('/\Avault:[A-Za-z0-9][A-Za-z0-9._:\/#-]{0,199}\z/D', $value) === 1;
    }

    /**
     * The part after `vault:` (e.g. the Vault item id).
     */
    public function target(): string
    {
        return substr($this->value, 6);
    }

    /**
     * The reference.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
