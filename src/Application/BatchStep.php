<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * What every batch of one run shares: the run id, the number of writing batches, the leases held and the claim (fencing
 * version and lease holder). Per run.
 *
 * @internal
 */
final readonly class BatchStep
{
    /**
     * @param list<ProviderName> $held
     */
    public function __construct(public RunId $runId, public int $total, public array $held, public RunClaim $claim)
    {
    }
}
