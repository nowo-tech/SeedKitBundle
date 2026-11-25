<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Event;

use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * Dispatched when a run stopped after it was created (error code and committed batches only).
 */
final readonly class SeedRunFailedEvent
{
    /**
     * Builds the event.
     */
    public function __construct(public RunId $runId, public string $errorCode, public int $batchesCommitted)
    {
    }
}
