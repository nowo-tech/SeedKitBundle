<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Model;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Stringable;

/**
 * Who runs an export or an apply: the user identifier (web) or `--actor` (console). 1–190 bytes of exact text. The
 * reserved {@see SYSTEM} actor is only reachable from the console channel (QB-SK-5).
 */
final readonly class ActorRef implements Stringable
{
    /** The console actor of fresh installs (`--actor=system`). */
    public const SYSTEM = 'system';

    /**
     * @throws InvalidValueException
     */
    public function __construct(public string $value)
    {
        ExactText::assert($value, 190, 'Invalid actor reference.');
    }

    /**
     * The system actor.
     */
    public static function system(): self
    {
        return new self(self::SYSTEM);
    }

    /**
     * Whether this is the reserved system actor.
     */
    public function isSystem(): bool
    {
        return $this->value === self::SYSTEM;
    }

    /**
     * The reference.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
