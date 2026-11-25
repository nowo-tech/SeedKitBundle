<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_012: a prune guard refused the plan (contract section 10.4): a mass prune above `prune.max_ratio` without the
 * explicit confirmation, or an empty document with prune without `--allow-empty-prune`. Nothing was written.
 */
final class PruneRefusedException extends SeedKitException
{
    /** The prune would delete more than the configured ratio of the current rows. */
    public const MASS = 'mass';

    /** The document has no item and prune is on. */
    public const EMPTY = 'empty';

    /**
     * @param string $guard {@see self::MASS} or {@see self::EMPTY}
     * @param int $deletions rows the prune would delete
     * @param int $current rows currently in scope (updated + unchanged + deleted)
     */
    public function __construct(public readonly string $guard, public readonly int $deletions, public readonly int $current)
    {
        parent::__construct($guard === self::MASS
            ? 'The prune would delete ' . $deletions . ' of ' . $current . ' rows; confirm a mass prune explicitly.'
            : 'The document has no item and prune is on; confirm an empty prune explicitly.');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_012';
    }
}
