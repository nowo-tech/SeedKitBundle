<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Plan\SeedPlan;

/**
 * The outcome of an apply: the plan that was applied, the run (null when there was nothing to do), the batches
 * committed and the rows written (ChangeOp value → rows, as reported by the providers). `$finished` is false when the
 * run was taken over after its last batch: its new claimer ends it, so no applied event or success audit here.
 */
final readonly class ApplyResult
{
    /**
     * @param array<string, int> $written
     */
    public function __construct(public SeedPlan $plan, public ?RunId $runId, public int $batches, public array $written = [], public bool $finished = true)
    {
    }
}
