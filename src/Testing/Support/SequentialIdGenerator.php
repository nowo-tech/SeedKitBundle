<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Testing\Support;

use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Port\IdGeneratorInterface;

use function sprintf;

/**
 * Predictable run ids for tests (`00000000-0000-7000-8000-00000000000N`).
 */
final class SequentialIdGenerator implements IdGeneratorInterface
{
    private int $next = 0;

    /**
     * {@inheritDoc}
     */
    public function runId(): RunId
    {
        return new RunId(sprintf('00000000-0000-7000-8000-%012d', ++$this->next));
    }
}
