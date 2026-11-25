<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

/**
 * One side of a field change as shown in a diff: a value, hidden (a viewer without `SEED_EXPORT`), or absent.
 * Secret fields only ever show `vault:` references, never resolved values.
 */
final readonly class FieldValueView
{
    /** A JSON-compatible value. */
    public const VALUE = 'value';

    /** Hidden from this viewer. */
    public const HIDDEN = 'hidden';

    /** No value. */
    public const ABSENT = 'absent';

    /**
     * Builds a view (use the named constructors).
     */
    private function __construct(public string $kind, public mixed $value = null)
    {
    }

    /**
     * A value.
     */
    public static function value(mixed $value): self
    {
        return new self(self::VALUE, $value);
    }

    /**
     * Hidden from the viewer.
     */
    public static function hidden(): self
    {
        return new self(self::HIDDEN);
    }

    /**
     * No value.
     */
    public static function absent(): self
    {
        return new self(self::ABSENT);
    }
}
