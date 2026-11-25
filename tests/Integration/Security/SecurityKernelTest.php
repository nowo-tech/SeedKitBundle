<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Security;

use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Domain\Document\DocumentFormat;
use Nowo\SeedKitBundle\Domain\Document\ExportMode;
use Nowo\SeedKitBundle\Domain\Exception\AccessDeniedException;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedPermissionSubject;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function fclose;
use function fopen;

/**
 * SecurityBundle host (FR-006): `is_granted('SEED_*', subject)` through the voter, the role checker with the
 * configured role hierarchy and per-provider overrides, the token user as the actor.
 */
final class SecurityKernelTest extends KernelTestCase
{
    protected function setUp(): void
    {
        $this->boot(['security' => ['providers' => ['host.tags' => ['prune_roles' => ['ROLE_ADMIN']]]]], ['security' => true]);
    }

    private function login(string $identifier, string $role): void
    {
        $user = new InMemoryUser($identifier, null, [$role]);
        $this->service('security.token_storage', TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function granted(string $attribute, string $provider): bool
    {
        return $this->service('security.authorization_checker', AuthorizationCheckerInterface::class)->isGranted($attribute, new SeedPermissionSubject(new ProviderName($provider), SeedScope::platform()));
    }

    #[Test]
    public function theVoterFollowsTheRolesAndTheHierarchy(): void
    {
        $this->login('admin', 'ROLE_ADMIN');
        self::assertTrue($this->granted('SEED_EXPORT', 'host.tags'));
        self::assertTrue($this->granted('SEED_APPLY', 'host.articles'));
        self::assertTrue($this->granted('SEED_PRUNE', 'host.tags'), 'per-provider override');
        self::assertFalse($this->granted('SEED_PRUNE', 'host.articles'), 'prune: nobody by default');
        self::assertSame('admin', $this->service(SeedActorResolverInterface::class, SeedActorResolverInterface::class)->current()?->value);

        $this->login('super', 'ROLE_SUPER');
        self::assertTrue($this->granted('SEED_APPLY', 'host.tags'), 'ROLE_SUPER reaches ROLE_ADMIN');

        $this->login('viewer', 'ROLE_VIEWER');
        self::assertFalse($this->granted('SEED_EXPORT', 'host.tags'));
        $this->listener()->grant = ['SEED_EXPORT:host.tags'];
        self::assertTrue($this->granted('SEED_EXPORT', 'host.tags'), 'a host listener may grant');
    }

    #[Test]
    public function aConsoleUserIsCheckedLikeAnyOther(): void
    {
        $this->login('viewer', 'ROLE_VIEWER');
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            $this->expectException(AccessDeniedException::class);
            $this->service(SeedOperations::class, SeedOperations::class)->export(new ProviderName('host.tags'), SeedScope::platform(), ExportMode::Current, DocumentFormat::Json, new ActorRef('viewer'), Channel::Console, $stream);
        } finally {
            fclose($stream);
        }
    }
}
