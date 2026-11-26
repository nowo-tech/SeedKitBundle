<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Unit\Bridge\Messenger;

use DateTimeImmutable;
use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun;
use Nowo\SeedKitBundle\Bridge\Messenger\MessengerRunQueue;
use Nowo\SeedKitBundle\Bridge\Scheduler\PurgeSeedRuns;
use Nowo\SeedKitBundle\Bridge\Scheduler\SeedKitPurgeSchedule;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Scheduler\Generator\MessageContext;

/**
 * The queue port puts {@see ApplySeedRun} on the bus with ids only; the schedule recurs the purge message.
 */
final class MessengerBridgeTest extends TestCase
{
    #[Test]
    public function theQueueDispatchesIdsOnly(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;

                return new Envelope($message);
            }
        };
        (new MessengerRunQueue($bus))->queue(new RunId('01936f2a-0000-7000-8000-000000000001'), new PlanHash('sk1:' . str_repeat('a', 64)));

        self::assertEquals([new ApplySeedRun('01936f2a-0000-7000-8000-000000000001', 'sk1:' . str_repeat('a', 64))], $bus->messages);
    }

    #[Test]
    public function theScheduleRecursThePurge(): void
    {
        $messages = (new SeedKitPurgeSchedule())->getSchedule()->getRecurringMessages();
        self::assertCount(1, $messages);
        $produced = $messages[0]->getMessages(new MessageContext('nowo_seed_kit', 'id', $messages[0]->getTrigger(), new DateTimeImmutable()));
        foreach ($produced as $message) {
            self::assertInstanceOf(PurgeSeedRuns::class, $message);
        }
    }
}
