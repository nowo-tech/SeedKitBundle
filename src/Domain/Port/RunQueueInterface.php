<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * Hands a queued run to the asynchronous apply (contract section 10.5; Messenger bridge). The message carries the run
 * id and the reviewed plan hash only: never the document, a value or a secret.
 */
interface RunQueueInterface
{
    /**
     * Queues the apply of the run.
     */
    public function queue(RunId $run, PlanHash $planHash): void;
}
