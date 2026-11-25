<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Domain\Run;

/**
 * The life of a run row (contract section 5.4). 0.1 writes `Running`, `Applied`, `PartiallyApplied` and `Failed`
 * (console runs); `Planned`, `Queued`, `Discarded` and `Expired` arrive with the UI and Messenger in 0.2.
 */
enum RunStatus: string
{
    case Planned          = 'planned';
    case Queued           = 'queued';
    case Running          = 'running';
    case Applied          = 'applied';
    case PartiallyApplied = 'partially_applied';
    case Failed           = 'failed';
    case Discarded        = 'discarded';
    case Expired          = 'expired';

    /**
     * Whether the run is over.
     */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Applied, self::Failed, self::Discarded, self::Expired => true,
            default                                                     => false,
        };
    }
}
