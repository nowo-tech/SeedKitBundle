<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedAccessCheckEvent;

use function in_array;

/**
 * Host listener of `nowo_seed_kit.access_check`: denies the listed attributes (`SEED_X` or `SEED_X:provider`), grants
 * the listed ones, counts the events and keeps the last one.
 */
final class AccessListener
{
    /** @var list<string> */
    public array $deny = [];

    /** @var list<string> */
    public array $grant = [];

    public int $events = 0;

    public ?SeedAccessCheckEvent $last = null;

    public function onAccessCheck(SeedAccessCheckEvent $event): void
    {
        ++$this->events;
        $this->last = $event;
        $attribute  = $event->action->attribute();
        $provider   = $attribute . ':' . $event->provider->value;
        if (in_array($attribute, $this->grant, true) || in_array($provider, $this->grant, true)) {
            $event->grant();
        }
        if (in_array($attribute, $this->deny, true) || in_array($provider, $this->deny, true)) {
            $event->deny();
        }
    }

    /**
     * Back to the default decisions.
     */
    public function reset(): void
    {
        $this->deny   = [];
        $this->grant  = [];
        $this->events = 0;
        $this->last   = null;
    }
}
