<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Model\RunId;

/**
 * The claim of one apply on its run row (contract 10.3, ZCE-L1): the fencing `version` the claimer last wrote (every
 * fenced write — batch progress, heartbeat, finish, release — requires it and moves it on, so a worker whose run was
 * taken over can no longer change the run) and the lease holder of this claim (a fresh id per claim, so the old
 * worker can neither renew nor release the new claimer's leases). Per apply, never kept on a service.
 */
final class RunClaim
{
    /**
     * @param RunId $run the run row
     * @param RunId $holder the lease holder of this claim
     * @param int $version the fencing version after the claim
     */
    public function __construct(public readonly RunId $run, public readonly RunId $holder, public int $version)
    {
    }

    /**
     * Records a successful fenced write (the row's version moved on by one).
     */
    public function advance(): void
    {
        // @igor-ignore - a per-apply value object created for each claim, never a service: nothing outlives the apply.
        ++$this->version;
    }
}
