<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Messenger;

/**
 * Messenger message of the asynchronous apply of a UI run (contract section 10.5). Stable public name: route
 * `Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun` to the host's async transport. It carries the run id and the
 * reviewed plan hash only (never the document, a value or a secret), so any serializer can carry it.
 */
final readonly class ApplySeedRun
{
    /**
     * @param string $runId the run (UUID v7)
     * @param string $planHash the reviewed plan hash (`sk1:…`)
     */
    public function __construct(public string $runId, public string $planHash)
    {
    }
}
