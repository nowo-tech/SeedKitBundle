<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\Security;

use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The default actor: the security user identifier (read on every call), or null without a user. An identifier that
 * is not exact text, or the reserved `system`, gives no actor.
 */
final readonly class SecurityActorResolver implements SeedActorResolverInterface
{
    /**
     * Builds the resolver.
     */
    public function __construct(private ?TokenStorageInterface $tokens = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function current(): ?ActorRef
    {
        $identifier = $this->tokens?->getToken()?->getUserIdentifier();
        if ($identifier === null || $identifier === ActorRef::SYSTEM) {
            return null;
        }
        try {
            return new ActorRef($identifier);
        } catch (InvalidValueException) {
            return null;
        }
    }
}
