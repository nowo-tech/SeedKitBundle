<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Kernel;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorderInterface;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\SeedKitBundle\Bridge\Messenger\ApplySeedRun;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Event\SeedKitEvents;
use Nowo\SeedKitBundle\NowoSeedKitBundle;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingAuditSink;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingLogger;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\AccessListener;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\FixedActorResolver;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostArticleProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostCurrentScope;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostHooks;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostNoteProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostScopeResolver;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostTagProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\LintBrokenProvider;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\PermissionSetChecker;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Support\QueryLog;
use Nowo\SeedKitBundle\Tests\Support\QueryLogMiddleware;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_replace_recursive;
use function dirname;
use function md5;
use function serialize;
use function sys_get_temp_dir;

/**
 * Kernel of the integration and database tests: FrameworkBundle, DoctrineBundle, the bundle and the DB-backed fixture
 * providers; SecurityBundle on request; AuditKit and Vault collaborators as synthetic services on request. The clock is
 * a MockClock (2026-11-24 10:00 UTC), the logger a {@see RecordingLogger} (a NullLogger with `quiet`).
 *
 * Host kinds (REQ-ARCH-001): `standard` = default entity manager, attribute mapping, `host.tags` by `#[AsSeedProvider]`
 * and `host.articles` by an explicit tag, default resolvers and the role checker; `ddd` = a second connection and
 * entity manager `seed`, XML mapping, `host.tags` + `host.notes` (implements the interface directly) on the seed
 * connection, own scope resolver, actor resolver and permission-set checker; `named` = the default entity manager is
 * `main`; `first` = no default entity manager declared, the first one (`primary`) is used.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /** Directory console file arguments must resolve into. */
    public const ALLOWED_DIR = '/nowo-seed-kit-files/allowed';

    /**
     * @param array<string, mixed> $bundleConfig `nowo_seed_kit` configuration (merged over the host defaults)
     * @param array<string, bool> $features security, ui (TwigBundle, UiKit, FormKit, SecurityBundle with http_basic, the UI routes), messenger (with ui: in-memory `async` transport), vault, auditkit, broken, no_providers, quiet, query_log, no_debug (production-like: no traceable dispatcher, no DBAL logging)
     * @param 'ddd'|'first'|'named'|'standard' $host
     */
    public function __construct(
        private readonly array $bundleConfig = [],
        private readonly array $features = [],
        private readonly string $host = 'standard',
    ) {
        parent::__construct('test', !($features['no_debug'] ?? false));
    }

    /**
     * The allowed directory of console files.
     */
    public static function allowedDir(): string
    {
        return sys_get_temp_dir() . self::ALLOWED_DIR;
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        if ($this->feature('security') || $this->feature('ui')) {
            yield new SecurityBundle();
        }
        if ($this->feature('ui')) {
            yield new TwigBundle();
            yield new NowoUiKitBundle();
            yield new NowoFormKitBundle();
        }
        yield new NowoSeedKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-seed-kit-kernel/' . $this->id() . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-seed-kit-kernel/' . $this->id() . '/log';
    }

    /**
     * The host kind.
     */
    public function host(): string
    {
        return $this->host;
    }

    /**
     * The UI routes (feature `ui`).
     */
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->feature('ui')) {
            $routes->import('.', 'nowo_seed_kit');
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->feature('conflicting_runs')) {
            $container->setParameter('test.conflicting_runs', true);
        }
        $container->register('test.mock_clock', MockClock::class)->setArguments(['2026-11-24 10:00:00', 'UTC'])->setPublic(true);
        $container->register('logger', $this->feature('quiet') ? NullLogger::class : RecordingLogger::class)->setPublic(true);
        $container->register('test.secret_validator', PrefixSecretValidator::class);
        $container->register('test.audit', RecordingAuditSink::class)->setPublic(true);
        $container->register('test.hooks', HostHooks::class)->setPublic(true);
        $container->register('test.access_listener', AccessListener::class)->setPublic(true)
            ->addTag('kernel.event_listener', ['event' => SeedKitEvents::ACCESS_CHECK, 'method' => 'onAccessCheck']);
        if ($this->feature('query_log')) {
            $container->register('test.query_log', QueryLog::class)->setPublic(true);
            $container->register('test.query_log_middleware', QueryLogMiddleware::class)->setArguments([new Reference('test.query_log')])->addTag('doctrine.middleware');
        }
        $connection = new Reference($this->host === 'ddd' ? 'doctrine.dbal.seed_connection' : 'doctrine.dbal.default_connection');
        if (!$this->feature('no_providers')) {
            $container->register(HostTagProvider::class, HostTagProvider::class)->setArguments([$connection, new Reference('test.hooks')])->setAutoconfigured(true)->setPublic(true);
            if ($this->host === 'ddd') {
                $container->register(HostNoteProvider::class, HostNoteProvider::class)->setArguments([$connection])
                    ->addTag('nowo_seed_kit.provider', ['name' => 'host.notes', 'depends_on' => ['host.tags']]);
            } else {
                $container->register(HostArticleProvider::class, HostArticleProvider::class)->setArguments([$connection, new Reference('test.hooks')])
                    ->addTag('nowo_seed_kit.provider', ['name' => 'host.articles', 'depends_on' => ['host.tags']]);
                if ($this->feature('ui')) {
                    // A provider without personal data or secrets (its current export needs no confirmation).
                    $container->register(HostNoteProvider::class, HostNoteProvider::class)->setArguments([$connection])
                        ->addTag('nowo_seed_kit.provider', ['name' => 'host.notes', 'depends_on' => ['host.tags']]);
                }
            }
        }
        if ($this->feature('broken')) {
            $container->register(LintBrokenProvider::class, LintBrokenProvider::class)->addTag('nowo_seed_kit.provider', ['name' => 'host.broken']);
        }
        $container->register('test.scope_resolver', HostScopeResolver::class);
        $container->register('test.current_scope', HostCurrentScope::class);
        $container->register('test.actor_resolver', FixedActorResolver::class);
        $container->register('test.access_checker', PermissionSetChecker::class)->setArguments([['admin' => ['area', 'export:host.tags', 'apply:host.tags'], 'messenger-bot' => ['apply:host.tags']]]);
        if ($this->feature('vault')) {
            $container->register(VaultItemQueryInterface::class)->setSynthetic(true)->setPublic(true);
            $container->register(VaultAccessCheckInterface::class)->setSynthetic(true)->setPublic(true);
        }
        if ($this->feature('auditkit')) {
            $container->register(ActionLogRecorderInterface::class)->setSynthetic(true)->setPublic(true);
        }
        $container->addCompilerPass(new TestOverridesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1000);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'seed-kit-test',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'translator'            => ['fallbacks' => ['en']],
            'cache'                 => ['app' => 'cache.adapter.array'],
        ]);

        $url = TestDatabase::url();
        $container->extension('doctrine', match ($this->host) {
            'ddd' => [
                'dbal' => ['default_connection' => 'default', 'connections' => ['default' => ['url' => $url], 'seed' => ['url' => $url]]],
                'orm'  => ['default_entity_manager' => 'default', 'entity_managers' => [
                    'default' => ['connection' => 'default'],
                    'seed'    => ['connection' => 'seed'],
                ]],
            ],
            'named' => ['dbal' => ['url' => $url], 'orm' => ['default_entity_manager' => 'main', 'entity_managers' => ['main' => ['connection' => 'default']]]],
            'first' => ['dbal' => ['url' => $url], 'orm' => ['entity_managers' => ['primary' => ['connection' => 'default'], 'secondary' => ['connection' => 'default']]]],
            default => ['dbal' => ['url' => $url], 'orm' => ['entity_managers' => ['default' => ['connection' => 'default']]]],
        });
        if ($this->feature('ui')) {
            $container->extension('framework', [
                'router'          => ['utf8' => true],
                'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
                'csrf_protection' => true,
                'form'            => true,
                'validation'      => true,
                'property_access' => true,
                'messenger'       => $this->feature('messenger') ? [
                    'transports' => ['async' => 'in-memory://'],
                    'routing'    => [ApplySeedRun::class => 'async'],
                ] : false,
            ]);
            $container->extension('twig', ['strict_variables' => true]);
            $container->extension('security', [
                'password_hashers' => [InMemoryUser::class => 'plaintext'],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                    'pruner' => ['password' => 'x', 'roles' => ['ROLE_ADMIN', 'ROLE_PRUNE']],
                    'viewer' => ['password' => 'x', 'roles' => ['ROLE_VIEWER']],
                    'bob'    => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                    'nomad'  => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                    'system' => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'memory', 'http_basic' => true]],
            ]);
        } elseif ($this->feature('security')) {
            $container->extension('security', [
                'providers' => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'x', 'roles' => ['ROLE_ADMIN']],
                    'super'  => ['password' => 'x', 'roles' => ['ROLE_SUPER']],
                    'viewer' => ['password' => 'x', 'roles' => ['ROLE_VIEWER']],
                ]]]],
                'role_hierarchy' => ['ROLE_SUPER' => ['ROLE_ADMIN']],
                'firewalls'      => ['main' => ['lazy' => true, 'provider' => 'memory']],
            ]);
        }
        $defaults = [
            'audit'   => ['auditkit' => false],
            'secrets' => ['validator' => 'test.secret_validator'],
            'console' => ['allowed_dirs' => [self::allowedDir()]],
        ];
        $defaults += $this->host === 'ddd'
            ? [
                'scope_kinds'    => ['platform' => ['requires_id' => false], 'organization' => ['requires_id' => true]],
                'scope_resolver' => 'test.scope_resolver',
                'actor_resolver' => 'test.actor_resolver',
                'doctrine'       => ['entity_manager' => 'seed', 'mapping' => 'xml'],
                'security'       => ['access_checker' => 'test.access_checker'],
            ]
            : ['doctrine' => ['mapping' => $this->host === 'standard' ? 'attribute' : 'xml']];
        $container->extension('nowo_seed_kit', array_replace_recursive($defaults, $this->bundleConfig));
    }

    private function feature(string $name): bool
    {
        return $this->features[$name] ?? false;
    }

    private function id(): string
    {
        return md5(serialize([$this->bundleConfig, $this->features, $this->host, TestDatabase::url()]));
    }
}
