<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Scheduler;

/**
 * Scheduler message: expire stored run documents and delete runs past `runs.history_days` (contract section 11).
 */
final readonly class PurgeSeedRuns
{
}
