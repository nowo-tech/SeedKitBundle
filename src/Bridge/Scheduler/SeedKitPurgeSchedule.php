<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Bridge\Scheduler;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * The schedule `nowo_seed_kit` (Scheduler bridge, `scheduler.enabled`): {@see PurgeSeedRuns} every
 * `scheduler.frequency` (default one hour). Consume it with `messenger:consume scheduler_nowo_seed_kit`.
 */
final readonly class SeedKitPurgeSchedule implements ScheduleProviderInterface
{
    /** Schedule name (transport `scheduler_nowo_seed_kit`). */
    public const NAME = 'nowo_seed_kit';

    /**
     * @param string $frequency a `RecurringMessage::every()` frequency (`1 hour`, `PT30M`)
     */
    public function __construct(private string $frequency = '1 hour')
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every($this->frequency, new PurgeSeedRuns()));
    }
}
