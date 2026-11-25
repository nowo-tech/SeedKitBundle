<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

use function array_fill;
use function array_keys;
use function array_values;
use function count;
use function implode;
use function is_int;
use function sprintf;

/**
 * Platform-specific SQL the query builder does not cover.
 */
final class DbalSql
{
    /**
     * Inserts a row unless the unique key already holds one: `ON DUPLICATE KEY UPDATE` (MySQL / MariaDB), `ON CONFLICT
     * DO NOTHING` (PostgreSQL, SQLite). No error is raised, so a PostgreSQL transaction is never aborted.
     *
     * @param array<string, int|string> $row
     * @param list<string> $conflict the unique key's columns
     *
     * @return int rows inserted (0 or 1; MySQL may report 0 for a no-op update)
     */
    public static function insertIgnore(Connection $connection, string $table, array $row, array $conflict): int
    {
        $columns = implode(', ', array_keys($row));
        $holders = implode(', ', array_fill(0, count($row), '?'));
        $suffix  = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? sprintf('ON DUPLICATE KEY UPDATE %s = %s', $conflict[0], $conflict[0]) : sprintf('ON CONFLICT (%s) DO NOTHING', implode(', ', $conflict));
        $sql     = sprintf('INSERT INTO %s (%s) VALUES (%s) %s', $table, $columns, $holders, $suffix);
        $types   = [];
        foreach ($row as $value) {
            $types[] = is_int($value) ? ParameterType::INTEGER : ParameterType::STRING;
        }

        return (int) $connection->executeStatement($sql, array_values($row), $types);
    }
}
