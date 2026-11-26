<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Scheduler;

use Nowo\SeedKitBundle\Application\RunMaintenance;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs the purge of {@see PurgeSeedRuns}: what `nowo:seed-kit:runs:purge` does without options. Idempotent (a second
 * run finds nothing); a failure is rethrown for the transport and the next tick tries again.
 */
#[AsMessageHandler]
final readonly class PurgeSeedRunsHandler
{
    /**
     * Builds the handler.
     */
    public function __construct(private RunMaintenance $maintenance, private ?LoggerInterface $logger = null)
    {
    }

    /**
     * Handles the message.
     */
    public function __invoke(PurgeSeedRuns $message): void
    {
        $expired = $this->maintenance->expire();
        $deleted = $this->maintenance->purge($this->maintenance->defaultBefore(), false);
        $this->logger?->info('Seed runs purged: {expired} document(s) expired, {deleted} run(s) deleted.', ['expired' => $expired, 'deleted' => $deleted]);
    }
}
