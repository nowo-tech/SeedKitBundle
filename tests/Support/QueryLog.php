<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use function count;
use function preg_match;

/**
 * The statements sent through connections that carry {@see QueryLogMiddleware} (SC-002 / SC-003 assertions).
 */
final class QueryLog
{
    /** @var list<string> */
    public array $statements = [];

    /**
     * Records one statement.
     */
    public function add(string $sql): void
    {
        $this->statements[] = $sql;
    }

    /**
     * Forgets what was recorded.
     */
    public function reset(): void
    {
        $this->statements = [];
    }

    /**
     * Statements that mention a host table of the fixtures (`host_*`).
     */
    public function hostStatements(): int
    {
        return $this->matching('/\bhost_\w+/i');
    }

    /**
     * INSERT / UPDATE / DELETE statements on a host table.
     */
    public function hostWrites(): int
    {
        return $this->matching('/^\s*(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+host_\w+/i');
    }

    /**
     * All statements.
     */
    public function total(): int
    {
        return count($this->statements);
    }

    /**
     * Statements matching a pattern.
     */
    public function matching(string $pattern): int
    {
        $count = 0;
        foreach ($this->statements as $sql) {
            if (preg_match($pattern, $sql) === 1) {
                ++$count;
            }
        }

        return $count;
    }
}
