<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Closure;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;

/**
 * Runs each unit of work directly, after an optional hook that sees the 1-based call number (it may change the
 * "database" or throw), with a configurable attempt number.
 */
final class HookedWriteRunner implements WriteRunnerInterface
{
    public int $calls = 0;

    /**
     * @param (Closure(int): void)|null $before
     */
    public function __construct(private readonly ?Closure $before = null, private readonly int $attempt = 1)
    {
    }

    public function write(callable $work): mixed
    {
        ++$this->calls;
        if ($this->before instanceof Closure) {
            ($this->before)($this->calls);
        }

        return $work($this->attempt);
    }
}
