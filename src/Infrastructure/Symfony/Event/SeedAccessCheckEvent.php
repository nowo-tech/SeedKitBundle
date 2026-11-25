<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Event;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * `nowo_seed_kit.access_check` (contract section 12.1): dispatched before every export, plan and apply with the action,
 * provider, scope, channel, actor, the security user (null in the console) and the default decision. `grant()` /
 * `deny()` override it; a deny sticks, so one listener can never re-open what another closed. In the console the
 * default is granted (an operator with shell access), so a host listener is how the console is restricted.
 */
final class SeedAccessCheckEvent extends Event
{
    private bool $granted = false;

    private bool $denied = false;

    /**
     * Builds the event.
     */
    public function __construct(
        public readonly SeedAction $action,
        public readonly ProviderName $provider,
        public readonly SeedScope $scope,
        public readonly Channel $channel,
        public readonly ActorRef $actor,
        public readonly ?object $user,
        public readonly bool $defaultGranted,
    ) {
    }

    /**
     * Grants (ignored after a deny).
     */
    public function grant(): void
    {
        if (!$this->denied) {
            // @igor-ignore - per-call event object, not a shared worker service.
            $this->granted = true;
        }
    }

    /**
     * Denies (sticks).
     */
    public function deny(): void
    {
        // @igor-ignore - per-call event object, not a shared worker service.
        $this->denied = true;
        // @igor-ignore - per-call event object, not a shared worker service.
        $this->granted = false;
    }

    /**
     * Whether a listener denied.
     */
    public function isDenied(): bool
    {
        return $this->denied;
    }

    /**
     * The final decision: denied ⇒ false, granted ⇒ true, else the default.
     */
    public function isGranted(): bool
    {
        return !$this->denied && ($this->granted || $this->defaultGranted);
    }
}
