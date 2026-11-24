<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Run;

use function strtoupper;

/**
 * The three permissions (contract section 12.1), also the voter attributes `SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE`.
 */
enum SeedAction: string
{
    case Export = 'export';
    case Apply  = 'apply';
    case Prune  = 'prune';

    /**
     * The voter attribute.
     */
    public function attribute(): string
    {
        return 'SEED_' . strtoupper($this->value);
    }

    /**
     * The action of a voter attribute, or null.
     */
    public static function fromAttribute(string $attribute): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->attribute() === $attribute) {
                return $case;
            }
        }

        return null;
    }
}
