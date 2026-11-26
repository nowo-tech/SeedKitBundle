<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Application;

use Nowo\SeedKitBundle\Domain\Run\RunRecord;

/**
 * The answer of a UI or Messenger apply of a stored run: applied now (with its result), queued for the asynchronous
 * apply, or untouched because another request already claimed it (`busy`: running, queued or already applied; a
 * double click or a redelivery is idempotent).
 */
final readonly class ApplyOutcome
{
    /** Applied in this call. */
    public const APPLIED = 'applied';

    /** Handed to the asynchronous apply. */
    public const QUEUED = 'queued';

    /** Claimed by another request, or already over. */
    public const BUSY = 'busy';

    /**
     * Builds the outcome.
     */
    public function __construct(public string $kind, public RunRecord $run, public ?ApplyResult $result = null)
    {
    }
}
