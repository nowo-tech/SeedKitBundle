<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Connection of {@see QueryLogMiddleware}: records prepared, queried and executed SQL.
 */
final class QueryLogConnection extends AbstractConnectionMiddleware
{
    public function __construct(Connection $connection, private readonly QueryLog $log)
    {
        parent::__construct($connection);
    }

    public function prepare(string $sql): Statement
    {
        $this->log->add($sql);

        return parent::prepare($sql);
    }

    public function query(string $sql): Result
    {
        $this->log->add($sql);

        return parent::query($sql);
    }

    public function exec(string $sql): int
    {
        $this->log->add($sql);

        return (int) parent::exec($sql);
    }
}
