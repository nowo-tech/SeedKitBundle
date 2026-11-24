<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

/**
 * The bundle's table names with the configured prefix (`doctrine.table_prefix`, default `nowo_`, REQ-ORM-002). The
 * DBAL stores and the Doctrine mapping listener both use it, so the prefix is never dead configuration.
 */
final readonly class TableNames
{
    /** Base name of the run history. */
    public const RUN = 'seed_run';

    /** Base name of the leases. */
    public const LEASE = 'seed_lease';

    /**
     * @param string $prefix `^[a-z][a-z0-9_]{0,15}$` or empty (checked by the configuration)
     */
    public function __construct(public string $prefix = 'nowo_')
    {
    }

    /**
     * The prefixed run table.
     */
    public function run(): string
    {
        return $this->prefix . self::RUN;
    }

    /**
     * The prefixed lease table.
     */
    public function lease(): string
    {
        return $this->prefix . self::LEASE;
    }
}
