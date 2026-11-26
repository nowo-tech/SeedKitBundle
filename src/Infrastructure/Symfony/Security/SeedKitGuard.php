<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedAccessCheckEvent;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedKitEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * The guard (house access pattern, contract section 12.1), asked by the application layer before every export, plan
 * and apply. Default decision, then `nowo_seed_kit.access_check` listeners:
 *
 * - console without a security user: granted (operator with shell access; QB-SK-5 `--actor=system`), listeners may deny;
 * - Messenger (0.2): the checker's `canForActor()` for the stored actor, or `messenger.trust_run_actor`;
 * - HTTP / console with a user: area (`canUseArea`) and action (`can`) of the checker;
 * - no user elsewhere: denied unless `allow_unauthenticated` (demos).
 *
 * {@see isGranted()} reads the user from the token storage on every call, {@see isGrantedForUser()} takes it from the
 * caller (the voter's token); nothing is kept.
 */
final readonly class SeedKitGuard implements SeedAccessGateInterface
{
    /**
     * Builds the guard.
     */
    public function __construct(
        private SeedKitAccessCheckerInterface $checker,
        private EventDispatcherInterface $dispatcher,
        private bool $allowUnauthenticated = false,
        private bool $trustRunActor = false,
        private ?TokenStorageInterface $tokens = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool
    {
        return $this->isGrantedForUser($action, $provider, $scope, $channel, $actor, $this->tokens?->getToken()?->getUser());
    }

    /**
     * The same decision for a given security user instead of the token storage's one: the voter passes the user of
     * the token being voted on, which may be another user than the session's (or none).
     */
    public function isGrantedForUser(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor, ?object $user): bool
    {
        $event = new SeedAccessCheckEvent($action, $provider, $scope, $channel, $actor, $user, $this->defaultDecision($action, $provider, $scope, $channel, $actor, $user));
        $this->dispatcher->dispatch($event, SeedKitEvents::ACCESS_CHECK);

        return $event->isGranted();
    }

    /**
     * Whether the current user may open the UI area of the scope (`security.access_roles` / the checker's
     * `canUseArea()`): false without a user unless `allow_unauthenticated` (demos).
     */
    public function canUseArea(SeedScope $scope): bool
    {
        $user = $this->tokens?->getToken()?->getUser();
        if (!$user instanceof UserInterface && !$this->allowUnauthenticated) {
            return false;
        }

        return $this->checker->canUseArea($user, $scope);
    }

    /**
     * The decision before listeners.
     */
    private function defaultDecision(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor, ?object $user): bool
    {
        if ($channel === Channel::Messenger) {
            return $this->trustRunActor || $this->checker->canForActor($actor, $action, $provider, $scope);
        }
        if ($user === null) {
            if ($channel === Channel::Console) {
                return true;
            }
            if (!$this->allowUnauthenticated) {
                return false;
            }
        }

        return $this->checker->canUseArea($user, $scope) && $this->checker->can($user, $action, $provider, $scope);
    }
}
