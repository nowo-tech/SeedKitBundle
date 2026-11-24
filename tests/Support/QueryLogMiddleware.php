<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * DBAL middleware recording every statement in a {@see QueryLog} (DoctrineBundle tag `doctrine.middleware`).
 */
final readonly class QueryLogMiddleware implements Middleware
{
    public function __construct(private QueryLog $log)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        return new QueryLogDriver($driver, $this->log);
    }
}
