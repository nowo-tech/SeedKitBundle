<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Security;

use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\SeedAction;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedAccessCheckEvent;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedKitEvents;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\AllowAllSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\ConfigurableSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\PlatformScopeResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitGuard;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitVoter;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedPermissionSubject;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\AccessListener;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\PermissionSetChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The house access pattern (contract section 12.1) without a kernel: the access event, the guard on every channel,
 * the role checker, the demo checker, the voter and the default resolvers.
 */
final class AccessTest extends TestCase
{
    private static function tags(): ProviderName
    {
        return new ProviderName('host.tags');
    }

    private static function tokens(?InMemoryUser $user): TokenStorage
    {
        $tokens = new TokenStorage();
        if ($user instanceof InMemoryUser) {
            $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return $tokens;
    }

    private static function guard(SeedKitAccessCheckerInterface $checker, AccessListener $listener, bool $allowUnauthenticated = false, bool $trust = false, ?TokenStorage $tokens = null): SeedKitGuard
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SeedKitEvents::ACCESS_CHECK, $listener->onAccessCheck(...));

        return new SeedKitGuard($checker, $dispatcher, $allowUnauthenticated, $trust, $tokens);
    }

    private static function roles(): ConfigurableSeedKitAccessChecker
    {
        return new ConfigurableSeedKitAccessChecker(
            ['ROLE_ADMIN', 'ROLE_SEED'],
            ['export' => ['ROLE_ADMIN', 'ROLE_SEED'], 'apply' => ['ROLE_ADMIN'], 'prune' => []],
            ['host.articles' => ['export' => null, 'apply' => ['ROLE_SEED'], 'prune' => ['ROLE_ADMIN']]],
            new RoleHierarchy(['ROLE_SUPER' => ['ROLE_ADMIN']]),
        );
    }

    #[Test]
    public function aDenyInTheEventSticks(): void
    {
        $event = new SeedAccessCheckEvent(SeedAction::Apply, self::tags(), SeedScope::platform(), Channel::Console, new ActorRef('alice'), null, true);
        self::assertTrue($event->isGranted());
        self::assertFalse($event->isDenied());

        $event->deny();
        $event->grant();
        self::assertFalse($event->isGranted());
        self::assertTrue($event->isDenied());

        $closed = new SeedAccessCheckEvent(SeedAction::Export, self::tags(), SeedScope::platform(), Channel::Http, new ActorRef('alice'), new stdClass(), false);
        self::assertFalse($closed->isGranted());
        $closed->grant();
        self::assertTrue($closed->isGranted());
        self::assertSame(SeedAction::Export, $closed->action);
        self::assertSame('host.tags', $closed->provider->value);
        self::assertSame(Channel::Http, $closed->channel);
        self::assertSame('alice', $closed->actor->value);
        self::assertInstanceOf(stdClass::class, $closed->user);
        self::assertFalse($closed->defaultGranted);
        self::assertSame('platform', $closed->scope->key());
    }

    #[Test]
    public function theConsoleIsGrantedByDefaultAndAListenerMayDeny(): void
    {
        $listener = new AccessListener();
        $guard    = self::guard(self::roles(), $listener);

        self::assertTrue($guard->isGranted(SeedAction::Apply, self::tags(), SeedScope::platform(), Channel::Console, new ActorRef('ops')));
        self::assertNotNull($listener->last);
        self::assertTrue($listener->last->defaultGranted);
        self::assertNull($listener->last->user);

        $listener->deny = ['SEED_APPLY:host.tags'];
        self::assertFalse($guard->isGranted(SeedAction::Apply, self::tags(), SeedScope::platform(), Channel::Console, new ActorRef('ops')));
        self::assertTrue($guard->isGranted(SeedAction::Export, self::tags(), SeedScope::platform(), Channel::Console, new ActorRef('ops')));
    }

    #[Test]
    public function withoutAUserOutsideTheConsoleOnlyTheDemoSwitchOpens(): void
    {
        $listener = new AccessListener();

        self::assertFalse(self::guard(new AllowAllSeedKitAccessChecker(), $listener, false, false, self::tokens(null))->isGranted(SeedAction::Export, self::tags(), SeedScope::platform(), Channel::Http, new ActorRef('guest')));
        self::assertTrue(self::guard(new AllowAllSeedKitAccessChecker(), $listener, true)->isGranted(SeedAction::Prune, self::tags(), SeedScope::platform(), Channel::Http, new ActorRef('guest')));
        self::assertFalse(self::guard(self::roles(), $listener, true)->isGranted(SeedAction::Export, self::tags(), SeedScope::platform(), Channel::Http, new ActorRef('guest')));

        $listener->grant = ['SEED_EXPORT'];
        self::assertTrue(self::guard(self::roles(), $listener)->isGranted(SeedAction::Export, self::tags(), SeedScope::platform(), Channel::Http, new ActorRef('guest')), 'a host listener may grant');
    }

    #[Test]
    public function aUserIsCheckedForTheAreaAndTheAction(): void
    {
        $listener = new AccessListener();
        $admin    = self::tokens(new InMemoryUser('admin', null, ['ROLE_ADMIN']));
        $seeder   = self::tokens(new InMemoryUser('seeder', null, ['ROLE_SEED']));
        $super    = self::tokens(new InMemoryUser('super', null, ['ROLE_SUPER']));
        $viewer   = self::tokens(new InMemoryUser('viewer', null, ['ROLE_VIEWER']));
        $articles = new ProviderName('host.articles');
        $scope    = SeedScope::platform();
        $actor    = new ActorRef('x');

        self::assertTrue(self::guard(self::roles(), $listener, false, false, $admin)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Http, $actor));
        self::assertTrue(self::guard(self::roles(), $listener, false, false, $super)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Console, $actor), 'role hierarchy; a console user is checked too');
        self::assertFalse(self::guard(self::roles(), $listener, false, false, $viewer)->isGranted(SeedAction::Export, self::tags(), $scope, Channel::Http, $actor), 'no area role');
        self::assertFalse(self::guard(self::roles(), $listener, false, false, $seeder)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Http, $actor), 'apply needs ROLE_ADMIN');
        self::assertTrue(self::guard(self::roles(), $listener, false, false, $seeder)->isGranted(SeedAction::Apply, $articles, $scope, Channel::Http, $actor), 'per-provider override');
        self::assertTrue(self::guard(self::roles(), $listener, false, false, $seeder)->isGranted(SeedAction::Export, $articles, $scope, Channel::Http, $actor), 'null override keeps the default');
        self::assertFalse(self::guard(self::roles(), $listener, false, false, $admin)->isGranted(SeedAction::Prune, self::tags(), $scope, Channel::Http, $actor), 'prune: nobody by default');
        self::assertTrue(self::guard(self::roles(), $listener, false, false, $admin)->isGranted(SeedAction::Prune, $articles, $scope, Channel::Http, $actor));
    }

    #[Test]
    public function messengerUsesTheStoredActor(): void
    {
        $listener = new AccessListener();
        $checker  = new PermissionSetChecker(['messenger-bot' => ['apply:host.tags']]);
        $scope    = SeedScope::platform();

        self::assertTrue(self::guard($checker, $listener)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Messenger, new ActorRef('messenger-bot')));
        self::assertFalse(self::guard($checker, $listener)->isGranted(SeedAction::Prune, self::tags(), $scope, Channel::Messenger, new ActorRef('messenger-bot')));
        self::assertFalse(self::guard(self::roles(), $listener)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Messenger, new ActorRef('messenger-bot')), 'the role checker denies stored actors');
        self::assertTrue(self::guard(self::roles(), $listener, false, true)->isGranted(SeedAction::Apply, self::tags(), $scope, Channel::Messenger, new ActorRef('anyone')), 'trust_run_actor');
    }

    #[Test]
    public function theRoleCheckerDeniesWhatIsNotAUser(): void
    {
        $checker = new ConfigurableSeedKitAccessChecker(['ROLE_ADMIN'], ['export' => ['ROLE_ADMIN'], 'apply' => ['ROLE_ADMIN'], 'prune' => []]);
        $scope   = SeedScope::platform();

        self::assertFalse($checker->canUseArea(null, $scope));
        self::assertFalse($checker->canUseArea(new stdClass(), $scope));
        self::assertTrue($checker->canUseArea(new InMemoryUser('a', null, ['ROLE_ADMIN']), $scope));
        self::assertFalse($checker->canUseArea(new InMemoryUser('s', null, ['ROLE_SUPER']), $scope), 'no hierarchy given');
        self::assertTrue($checker->can(new InMemoryUser('a', null, ['ROLE_ADMIN']), SeedAction::Export, self::tags(), $scope));
        self::assertFalse($checker->can(new InMemoryUser('a', null, ['ROLE_ADMIN']), SeedAction::Prune, self::tags(), $scope));
        self::assertFalse($checker->canForActor(new ActorRef('a'), SeedAction::Export, self::tags(), $scope));
    }

    #[Test]
    public function theDemoCheckerGrantsEverything(): void
    {
        $checker = new AllowAllSeedKitAccessChecker();

        self::assertTrue($checker->canUseArea(null, SeedScope::platform()));
        self::assertTrue($checker->can(null, SeedAction::Prune, self::tags(), SeedScope::platform()));
        self::assertTrue($checker->canForActor(new ActorRef('a'), SeedAction::Prune, self::tags(), SeedScope::platform()));
    }

    #[Test]
    public function theVoterAsksTheGateForTheTokenUser(): void
    {
        $gate = new class implements SeedAccessGateInterface {
            /** @var list<string> */
            public array $asked = [];

            public function isGranted(SeedAction $action, ProviderName $provider, SeedScope $scope, Channel $channel, ActorRef $actor): bool
            {
                $this->asked[] = $action->attribute() . ' ' . $provider->value . ' ' . $scope->key() . ' ' . $channel->value . ' ' . $actor->value;

                return $action !== SeedAction::Prune;
            }
        };
        $voter   = new SeedKitVoter($gate);
        $subject = new SeedPermissionSubject(self::tags(), new SeedScope(new ScopeKind('organization'), new ScopeId('acme')));
        $alice   = new UsernamePasswordToken(new InMemoryUser('alice', null), 'main');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, $subject, ['SEED_EXPORT']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, $subject, ['SEED_PRUNE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($alice, $subject, ['ROLE_ADMIN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($alice, new stdClass(), ['SEED_EXPORT']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), $subject, ['SEED_APPLY']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new UsernamePasswordToken(new InMemoryUser('system', null), 'main'), $subject, ['SEED_APPLY']), 'the reserved system identifier');
        self::assertSame(['SEED_EXPORT host.tags organization:acme http alice', 'SEED_PRUNE host.tags organization:acme http alice'], $gate->asked);

        $vote = new ReflectionMethod(SeedKitVoter::class, 'voteOnAttribute');
        self::assertFalse($vote->invoke($voter, 'SEED_OTHER', $subject, $alice));
        self::assertFalse($vote->invoke($voter, 'SEED_EXPORT', new stdClass(), $alice));
    }

    #[Test]
    public function thePlatformResolverKnowsOnlyThePlatform(): void
    {
        $resolver = new PlatformScopeResolver();
        $actor    = new ActorRef('a');

        self::assertSame('platform', $resolver->resolve(SeedScope::platform(), $actor, Channel::Console)?->key());
        self::assertNull($resolver->resolve(new SeedScope(new ScopeKind('platform'), new ScopeId('x')), $actor, Channel::Console));
        self::assertNull($resolver->resolve(new SeedScope(new ScopeKind('organization'), new ScopeId('acme')), $actor, Channel::Http));
    }

    #[Test]
    public function theSecurityActorIsTheUserIdentifier(): void
    {
        self::assertNull((new SecurityActorResolver())->current());
        self::assertNull((new SecurityActorResolver(new TokenStorage()))->current());
        self::assertNull((new SecurityActorResolver(self::tokens(new InMemoryUser('system', null))))->current());
        self::assertNull((new SecurityActorResolver(self::tokens(new InMemoryUser("bad\u{200B}id", null))))->current(), 'not exact text');
        self::assertSame('alice', (new SecurityActorResolver(self::tokens(new InMemoryUser('alice', null))))->current()?->value);
    }
}
