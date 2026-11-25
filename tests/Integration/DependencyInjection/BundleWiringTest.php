<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorderInterface;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Bridge\AuditKit\AuditKitSeedAuditSink;
use Nowo\SeedKitBundle\Bridge\Vault\VaultSecretReferenceValidator;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalLeaseStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalWriteRunner;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity\SeedLease;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity\SeedRun;
use Nowo\SeedKitBundle\Infrastructure\Support\PsrLogAuditSink;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\AllowAllSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\ConfigurableSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\PlatformScopeResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitGuard;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitVoter;
use Nowo\SeedKitBundle\NowoSeedKitBundle;
use Nowo\SeedKitBundle\Tests\Fixtures\Application\RecordingAuditSink;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\FixedActorResolver;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostScopeResolver;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\PermissionSetChecker;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\PrefixSecretValidator;
use Nowo\SeedKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;

use function dirname;

/**
 * Container wiring of every configuration branch on both host kinds (REQ-ARCH-001): resolvers, checker, audit sink,
 * secret validator and bridges, command aliases, mapping and entity manager, table prefix.
 */
final class BundleWiringTest extends KernelTestCase
{
    #[Test]
    public function theStandardHostGetsTheDefaults(): void
    {
        $this->boot();

        self::assertInstanceOf(PlatformScopeResolver::class, $this->container()->get(SeedScopeResolverInterface::class));
        self::assertInstanceOf(SecurityActorResolver::class, $this->container()->get(SeedActorResolverInterface::class));
        self::assertInstanceOf(ConfigurableSeedKitAccessChecker::class, $this->container()->get(SeedKitAccessCheckerInterface::class));
        self::assertInstanceOf(PsrLogAuditSink::class, $this->container()->get(SeedAuditSinkInterface::class));
        self::assertInstanceOf(PrefixSecretValidator::class, $this->container()->get('nowo_seed_kit.secret_validator'));
        self::assertInstanceOf(DbalRunStore::class, $this->container()->get(RunStoreInterface::class));
        self::assertInstanceOf(DbalLeaseStore::class, $this->container()->get(LeaseStoreInterface::class));
        self::assertInstanceOf(DbalWriteRunner::class, $this->container()->get(WriteRunnerInterface::class));
        self::assertInstanceOf(SeedKitGuard::class, $this->container()->get(SeedKitGuard::class));
        self::assertInstanceOf(SeedKitVoter::class, $this->container()->get(SeedKitVoter::class));
        self::assertInstanceOf(CommandSupport::class, $this->container()->get(CommandSupport::class));
        self::assertSame('nowo_', $this->service(TableNames::class, TableNames::class)->prefix);
        self::assertSame(500, $this->service(SeedKitSettings::class, SeedKitSettings::class)->batchSize);

        $registry = $this->service(ProviderRegistry::class, ProviderRegistry::class);
        self::assertSame(['host.tags', 'host.articles'], $this->names($registry));

        $manager = $this->service('nowo_seed_kit.entity_manager', EntityManagerInterface::class);
        self::assertSame($this->service('doctrine.orm.default_entity_manager', EntityManagerInterface::class), $manager);
        self::assertSame($this->service('doctrine.dbal.default_connection', Connection::class), $this->connection());
        self::assertSame('nowo_seed_run', $manager->getClassMetadata(SeedRun::class)->getTableName());
        self::assertSame('nowo_seed_lease', $manager->getClassMetadata(SeedLease::class)->getTableName());
        self::assertInstanceOf(AttributeDriver::class, $this->driver($manager));

        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        foreach (['list', 'schema', 'export', 'diff', 'apply', 'runs:list', 'runs:purge', 'actor:forget', 'lint'] as $command) {
            self::assertSame($application->find('nowo:seed-kit:' . $command), $application->find('seed:' . $command));
        }
    }

    #[Test]
    public function theDddHostUsesItsOwnConnectionResolversAndChecker(): void
    {
        $this->boot([], [], 'ddd');

        self::assertInstanceOf(HostScopeResolver::class, $this->container()->get(SeedScopeResolverInterface::class));
        self::assertInstanceOf(FixedActorResolver::class, $this->container()->get(SeedActorResolverInterface::class));
        self::assertInstanceOf(PermissionSetChecker::class, $this->container()->get(SeedKitAccessCheckerInterface::class));
        self::assertSame(['host.tags', 'host.notes'], $this->names($this->service(ProviderRegistry::class, ProviderRegistry::class)));

        $manager = $this->service('nowo_seed_kit.entity_manager', EntityManagerInterface::class);
        self::assertSame($this->service('doctrine.orm.seed_entity_manager', EntityManagerInterface::class), $manager);
        self::assertSame($this->service('doctrine.dbal.seed_connection', Connection::class), $this->connection());
        self::assertNotSame($this->service('doctrine.dbal.default_connection', Connection::class), $this->connection());
        self::assertInstanceOf(SimplifiedXmlDriver::class, $this->driver($manager));
        self::assertNotContains(SeedRun::class, $this->mappedClasses($this->service('doctrine.orm.default_entity_manager', EntityManagerInterface::class)));
    }

    #[Test]
    public function aNamedDefaultManagerGetsTheMapping(): void
    {
        $this->boot([], [], 'named');

        $manager = $this->service('nowo_seed_kit.entity_manager', EntityManagerInterface::class);
        self::assertSame($this->service('doctrine.orm.main_entity_manager', EntityManagerInterface::class), $manager);
        self::assertSame('nowo_seed_run', $manager->getClassMetadata(SeedRun::class)->getTableName());
        self::assertInstanceOf(SimplifiedXmlDriver::class, $this->driver($manager));
    }

    #[Test]
    public function theFirstDeclaredManagerGetsTheMapping(): void
    {
        $this->boot([], [], 'first');

        self::assertSame($this->service('doctrine.orm.primary_entity_manager', EntityManagerInterface::class), $this->service('nowo_seed_kit.entity_manager', EntityManagerInterface::class));
        self::assertNotContains(SeedRun::class, $this->mappedClasses($this->service('doctrine.orm.secondary_entity_manager', EntityManagerInterface::class)));
    }

    #[Test]
    public function theTablePrefixReachesTheMappingAndTheStores(): void
    {
        $this->boot(['doctrine' => ['table_prefix' => 'acme_']]);

        self::assertSame('acme_seed_run', $this->service(TableNames::class, TableNames::class)->run());
        $metadata = $this->service('nowo_seed_kit.entity_manager', EntityManagerInterface::class)->getClassMetadata(SeedRun::class);
        self::assertSame('acme_seed_run', $metadata->getTableName());
        self::assertArrayHasKey('acme_seed_run_scope_idx', $metadata->table['indexes'] ?? []);
        self::assertSame(0, TestDatabase::int($this->connection()->fetchOne('SELECT COUNT(*) FROM acme_seed_run')));
    }

    #[Test]
    public function shortAliasesCanBeTurnedOff(): void
    {
        $this->boot(['console' => ['short_aliases' => false]], [], 'standard', false);
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);

        self::assertSame('nowo:seed-kit:list', $application->find('nowo:seed-kit:list')->getName());
        $this->expectException(CommandNotFoundException::class);
        $application->find('seed:list');
    }

    #[Test]
    public function theCheckerCanBeAllowAllOrAHostService(): void
    {
        $this->boot(['security' => ['access_checker' => 'allow_all', 'allow_unauthenticated' => true]], [], 'standard', false);
        self::assertInstanceOf(AllowAllSeedKitAccessChecker::class, $this->container()->get(SeedKitAccessCheckerInterface::class));

        $this->boot(['security' => ['access_checker' => 'test.access_checker']], [], 'standard', false);
        self::assertInstanceOf(PermissionSetChecker::class, $this->container()->get(SeedKitAccessCheckerInterface::class));
    }

    #[Test]
    public function resolversCanBeOverridden(): void
    {
        $this->boot(['scope_resolver' => 'test.scope_resolver', 'actor_resolver' => 'test.actor_resolver'], [], 'standard', false);

        self::assertInstanceOf(HostScopeResolver::class, $this->container()->get(SeedScopeResolverInterface::class));
        self::assertInstanceOf(FixedActorResolver::class, $this->container()->get(SeedActorResolverInterface::class));
    }

    #[Test]
    public function theAuditSinkFollowsTheConfiguration(): void
    {
        $this->boot(['audit' => ['sink' => 'test.audit']], [], 'standard', false);
        self::assertInstanceOf(RecordingAuditSink::class, $this->container()->get(SeedAuditSinkInterface::class));

        $this->boot(['audit' => ['auditkit' => true]], ['auditkit' => true], 'standard', false);
        self::assertNotNull($this->kernel);
        $this->kernel->getContainer()->set(ActionLogRecorderInterface::class, $this->createStub(ActionLogRecorderInterface::class));
        self::assertInstanceOf(AuditKitSeedAuditSink::class, $this->container()->get(SeedAuditSinkInterface::class));

        $this->boot(['audit' => ['auditkit' => true, 'sink' => 'test.audit']], ['auditkit' => true], 'standard', false);
        self::assertInstanceOf(RecordingAuditSink::class, $this->container()->get(SeedAuditSinkInterface::class));

        // AuditKit installed but its bundle not registered: the log sink stays and the container still builds.
        $this->boot(['audit' => ['auditkit' => true]], [], 'standard', false);
        self::assertInstanceOf(PsrLogAuditSink::class, $this->container()->get(SeedAuditSinkInterface::class));
    }

    #[Test]
    public function theVaultBridgeValidatesSecretReferences(): void
    {
        $this->boot(['secrets' => ['validator' => null, 'vault_bridge' => ['enabled' => true, 'scope_format' => '{kind}']]], ['vault' => true], 'standard', false);
        self::assertNotNull($this->kernel);
        $this->kernel->getContainer()->set(VaultItemQueryInterface::class, $this->createStub(VaultItemQueryInterface::class));
        $this->kernel->getContainer()->set(VaultAccessCheckInterface::class, $this->createStub(VaultAccessCheckInterface::class));

        self::assertInstanceOf(VaultSecretReferenceValidator::class, $this->container()->get('nowo_seed_kit.secret_validator'));
    }

    #[Test]
    public function withoutAValidatorNoSecretValidatorIsWired(): void
    {
        $this->boot(['secrets' => ['validator' => null]], [], 'standard', false);

        self::assertFalse($this->container()->has('nowo_seed_kit.secret_validator'));
        self::assertSame(['host.tags', 'host.articles'], $this->names($this->service(ProviderRegistry::class, ProviderRegistry::class)));
    }

    #[Test]
    public function theBundleDescribesItself(): void
    {
        $bundle = new NowoSeedKitBundle();

        self::assertSame(dirname(__DIR__, 3) . '/src', $bundle->getPath());
        self::assertSame('nowo_seed_kit', $bundle->getContainerExtension()?->getAlias());
    }

    /**
     * @return list<string>
     */
    private function names(ProviderRegistry $registry): array
    {
        $names = [];
        foreach ($registry->names() as $name) {
            $names[] = $name->value;
        }

        return $names;
    }

    /**
     * The mapping driver of the bundle's entities.
     */
    private function driver(EntityManagerInterface $manager): MappingDriver
    {
        $driver = $manager->getConfiguration()->getMetadataDriverImpl();
        if ($driver instanceof BundleMappingDriver) {
            $driver = $driver->getDriver();
        }
        self::assertInstanceOf(MappingDriverChain::class, $driver);
        foreach ($driver->getDrivers() as $namespace => $inner) {
            if ($namespace === 'Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity') {
                return $inner;
            }
        }
        self::fail('The bundle mapping is not registered.');
    }

    /**
     * The classes the manager's mapping drivers know (an entity manager without the bundle's mapping lists none of them).
     *
     * @return list<string>
     */
    private function mappedClasses(EntityManagerInterface $manager): array
    {
        $driver = $manager->getConfiguration()->getMetadataDriverImpl();

        return $driver === null ? [] : array_values($driver->getAllClassNames());
    }
}
