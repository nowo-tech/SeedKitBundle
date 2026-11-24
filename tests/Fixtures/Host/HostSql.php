<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

use function is_numeric;
use function is_scalar;

/**
 * SQL helpers of the DB-backed fixture providers.
 */
final class HostSql
{
    /**
     * ` FOR UPDATE` where the platform has row locks (not SQLite: its write transaction locks the file).
     */
    public static function lock(Connection $connection, bool $lock): string
    {
        return $lock && !$connection->getDatabasePlatform() instanceof SQLitePlatform ? ' FOR UPDATE' : '';
    }

    /**
     * A column or item value as text.
     */
    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A nullable text column.
     */
    public static function nullableText(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * A nullable integer column.
     */
    public static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
