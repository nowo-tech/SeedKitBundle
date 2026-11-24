<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Keeps every dispatched event.
 */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /**
     * The short class names of the events, in order.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->events as $event) {
            $parts   = explode('\\', $event::class);
            $names[] = end($parts);
        }

        return $names;
    }
}
