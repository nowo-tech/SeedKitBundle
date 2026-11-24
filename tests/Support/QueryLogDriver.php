<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * Driver of {@see QueryLogMiddleware}.
 */
final class QueryLogDriver extends AbstractDriverMiddleware
{
    public function __construct(Driver $driver, private readonly QueryLog $log)
    {
        parent::__construct($driver);
    }

    public function connect(#[SensitiveParameter] array $params): DriverConnection
    {
        return new QueryLogConnection(parent::connect($params), $this->log);
    }
}
