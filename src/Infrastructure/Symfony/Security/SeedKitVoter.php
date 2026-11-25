<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `SEED_EXPORT`, `SEED_APPLY`, `SEED_PRUNE` on a {@see SeedPermissionSubject} (FR-006): the same decision as the guard
 * (checker, then `nowo_seed_kit.access_check`) for the user of the token being voted on (not the session's) on the HTTP
 * channel; a replacement gate that is not the {@see SeedKitGuard} only gets the token's user identifier as actor. With Symfony's default
 * `affirmative` strategy a host voter that grants the same attributes wins over this one (contract F8): map
 * permissions with the checker or the event to keep deny-by-default.
 *
 * @extends Voter<string, SeedPermissionSubject>
 */
final class SeedKitVoter extends Voter
{
    /**
     * Builds the voter.
     */
    public function __construct(private readonly SeedAccessGateInterface $gate)
    {
    }

    /**
     * {@inheritDoc}
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return SeedAction::fromAttribute($attribute) instanceof SeedAction && $subject instanceof SeedPermissionSubject;
    }

    /**
     * {@inheritDoc}
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $action = SeedAction::fromAttribute($attribute);
        if (!$action instanceof SeedAction || !$subject instanceof SeedPermissionSubject) {
            return false;
        }
        $identifier = $token->getUserIdentifier();
        if ($identifier === '' || $identifier === ActorRef::SYSTEM) {
            return false;
        }

        $actor = new ActorRef($identifier);
        if ($this->gate instanceof SeedKitGuard) {
            // The token being voted on, never the session's: a host may decide for another user (ZBK L3).
            return $this->gate->isGrantedForUser($action, $subject->provider, $subject->scope, Channel::Http, $actor, $token->getUser());
        }

        return $this->gate->isGranted($action, $subject->provider, $subject->scope, Channel::Http, $actor);
    }
}
