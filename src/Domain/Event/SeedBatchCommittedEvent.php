<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Event;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * Dispatched after each batch commit (ids and counts only).
 */
final readonly class SeedBatchCommittedEvent
{
    /**
     * Builds the event.
     */
    public function __construct(
        public RunId $runId,
        public ProviderName $provider,
        public int $batchNumber,
        public int $created,
        public int $updated,
        public int $deleted,
    ) {
    }
}
