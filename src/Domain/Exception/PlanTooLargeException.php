<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Exception;

/**
 * SEED_009: the plan writes more rows than a web request may apply (`ui.max_sync_changes`) and the asynchronous apply
 * (Messenger) is not enabled: apply the file from the console instead (contract section 10.5).
 */
final class PlanTooLargeException extends SeedKitException
{
    /**
     * @param int $changes rows the plan writes
     * @param int $limit `ui.max_sync_changes`
     */
    public function __construct(public readonly int $changes, public readonly int $limit)
    {
        parent::__construct('The plan is too large to apply in a web request; apply the file from the console.');
    }

    /**
     * {@inheritDoc}
     */
    public function errorCode(): string
    {
        return 'SEED_009';
    }
}
