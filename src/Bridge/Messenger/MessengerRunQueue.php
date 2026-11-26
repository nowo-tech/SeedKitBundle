<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Messenger;

use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Port\RunQueueInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a UI run on the host's message bus as {@see ApplySeedRun} (contract section 10.5). Where it runs is the
 * host's routing (`framework.messenger.routing`); without a route the message is handled synchronously.
 */
final readonly class MessengerRunQueue implements RunQueueInterface
{
    /**
     * Builds the queue.
     */
    public function __construct(private MessageBusInterface $bus)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function queue(RunId $run, PlanHash $planHash): void
    {
        $this->bus->dispatch(new ApplySeedRun($run->value, $planHash->value));
    }
}
