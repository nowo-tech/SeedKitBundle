<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Port;

use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * Generates run ids (default: UUID v7).
 */
interface IdGeneratorInterface
{
    /**
     * A new run id.
     */
    public function runId(): RunId;
}
