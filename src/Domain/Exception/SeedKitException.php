<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

use RuntimeException;

/**
 * Base of every SeedKit failure (contract section 5.5). The message is static English text and never carries a
 * submitted value, a path, a natural key or a secret; {@see errorCode()} is the stable code (`SEED_00x`,
 * `ACCESS_00x`, `CONFLICT_00x`) and {@see messageKey()} the translation key in the `NowoSeedKitBundle` domain.
 */
abstract class SeedKitException extends RuntimeException
{
    /**
     * The stable error code.
     */
    abstract public function errorCode(): string;

    /**
     * The translation key of the static message.
     */
    public function messageKey(): string
    {
        return 'seed_kit.error.' . $this->errorCode();
    }
}
