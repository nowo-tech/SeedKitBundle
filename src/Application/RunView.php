<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;

/**
 * What the run page shows: the run row, and for its own actor while the document is kept, the plan as it is now (the
 * preview), whether it differs from the reviewed plan (`stale`) or could not be made (`error`, a static code), and
 * whether a `running` run stopped showing progress for longer than the lease (`stalled`: its request or worker died;
 * its actor may take it over or discard it, an admin of the kind may discard it).
 */
final readonly class RunView
{
    /**
     * Builds the view.
     */
    public function __construct(
        public RunRecord $run,
        public ?SeedPlan $plan = null,
        public bool $stale = false,
        public bool $owner = false,
        public ?string $error = null,
        public bool $stalled = false,
    ) {
    }
}
