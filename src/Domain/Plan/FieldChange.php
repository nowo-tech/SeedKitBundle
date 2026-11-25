<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

/**
 * One changed field of a row: its JSON Pointer inside the item (`/name`) and the before / after views.
 */
final readonly class FieldChange
{
    /**
     * Builds the change.
     */
    public function __construct(public string $path, public FieldValueView $before, public FieldValueView $after)
    {
    }
}
