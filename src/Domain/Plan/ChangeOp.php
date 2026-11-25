<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Plan;

/**
 * What a plan does with one row.
 */
enum ChangeOp: string
{
    case Create    = 'create';
    case Update    = 'update';
    case Unchanged = 'unchanged';
    case Delete    = 'delete';

    /**
     * The letter of the change line (contract section 9): `C`, `U`, `N`, `D`.
     */
    public function letter(): string
    {
        return match ($this) {
            self::Create    => 'C',
            self::Update    => 'U',
            self::Unchanged => 'N',
            self::Delete    => 'D',
        };
    }
}
