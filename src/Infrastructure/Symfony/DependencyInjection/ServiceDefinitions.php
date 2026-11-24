<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection;

use Composer\InstalledVersions;
use Nowo\AuditKitBundle\ActionLog\ActionLogRecorderInterface;
use Nowo\SeedKitBundle\Application\Applier;
use Nowo\SeedKitBundle\Application\Exporter;
use Nowo\SeedKitBundle\Application\ItemNormaliser;
use Nowo\SeedKitBundle\Application\Planner;
use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Application\RunMaintenance;
use Nowo\SeedKitBundle\Application\SchemaLinter;
use Nowo\SeedKitBundle\Application\SchemaPublisher;
use Nowo\SeedKitBundle\Application\SeedKitSettings;
use Nowo\SeedKitBundle\Application\SeedOperations;
use Nowo\SeedKitBundle\Bridge\AuditKit\AuditKitSeedAuditSink;
use Nowo\SeedKitBundle\Bridge\Vault\VaultSecretReferenceValidator;
use Nowo\SeedKitBundle\Domain\Port\DocumentWriterInterface;
use Nowo\SeedKitBundle\Domain\Port\IdGeneratorInterface;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Port\SchemaValidatorInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAccessGateInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedActorResolverInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedAuditSinkInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedKitAccessCheckerInterface;
use Nowo\SeedKitBundle\Domain\Port\SeedScopeResolverInterface;
use Nowo\SeedKitBundle\Domain\Port\WriteRunnerInterface;
use Nowo\SeedKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalLeaseStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalRunStore;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalWriteRunner;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;
use Nowo\SeedKitBundle\Infrastructure\Reader\DocumentReader;
use Nowo\SeedKitBundle\Infrastructure\Reader\ReaderLimits;
use Nowo\SeedKitBundle\Infrastructure\Schema\OpisSchemaValidator;
use Nowo\SeedKitBundle\Infrastructure\Support\PsrLogAuditSink;
use Nowo\SeedKitBundle\Infrastructure\Support\UuidV7IdGenerator;
use Nowo\SeedKitBundle\Infrastructure\Writer\DocumentWriter;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute\AsSeedProvider;
use Nowo\SeedKitBundle\Infrastructure\Symfony\CacheWarmer\SchemaLintWarmer;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ActorForgetCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ApplyCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\CommandSupport;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ConsolePaths;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\DiffCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ExportCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\LintCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\ListCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\RunsListCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\RunsPurgeCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Command\SchemaCommand;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\SeedProviderPass;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\AllowAllSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\ConfigurableSeedKitAccessChecker;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\PlatformScopeResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SecurityActorResolver;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitGuard;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Security\SeedKitVoter;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnit;
use Nowo\TransactionDoctrine\WriteUnit\WriteUnitPolicy;
use Nowo\VaultBundle\Service\VaultAccessCheckInterface;
use Nowo\VaultBundle\Service\VaultItemQueryInterface;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

use function class_exists;
use function interface_exists;
use function is_string;
use function sprintf;

/**
 * Registers the bundle's services from the processed configuration (contract section 15). Providers are collected by
 * {@see SeedProviderPass}; optional collaborators (logger, token storage, translator, AuditKit, Vault, Security) are
 * wired only when present.
 */
final class ServiceDefinitions
{
    /** Service and parameter prefix. */
    public const P = 'nowo_seed_kit.';

    /** Commands and their short aliases (Q-SK-8). */
    private const COMMANDS = [
        ListCommand::class        => ['nowo:seed-kit:list', 'seed:list'],
        SchemaCommand::class      => ['nowo:seed-kit:schema', 'seed:schema'],
        ExportCommand::class      => ['nowo:seed-kit:export', 'seed:export'],
        DiffCommand::class        => ['nowo:seed-kit:diff', 'seed:diff'],
        ApplyCommand::class       => ['nowo:seed-kit:apply', 'seed:apply'],
        RunsListCommand::class    => ['nowo:seed-kit:runs:list', 'seed:runs:list'],
        RunsPurgeCommand::class   => ['nowo:seed-kit:runs:purge', 'seed:runs:purge'],
        ActorForgetCommand::class => ['nowo:seed-kit:actor:forget', 'seed:actor:forget'],
        LintCommand::class        => ['nowo:seed-kit:lint', 'seed:lint'],
    ];

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config the processed configuration
     *
     * @throws InvalidArgumentException when a bridge is enabled without its package
     */
    public static function register(ContainerBuilder $container, array $config): void
    {
        /** @var array{scope_kinds: array<string, array{requires_id: bool}>, scope_resolver: ?string, actor_resolver: ?string, doctrine: array{entity_manager: ?string, table_prefix: string, mapping: string}, reader: array{max_bytes: int, max_tree_bytes: int, max_items: int, max_documents: int, max_depth: int, max_string_bytes: int, max_properties: int, max_line_bytes: int, max_nodes_per_item: int}, validation: array{max_errors: int}, apply: array{batch_size: int, lease_seconds: int}, transaction: array{max_replays: int}, prune: array{max_ratio: float}, plan: array{preview_rows: int}, runs: array{history_days: int}, secrets: array{validator: ?string, vault_bridge: array{enabled: bool, scope_format: string}}, audit: array{auditkit: bool, sink: ?string, log_level: string}, console: array{require_actor: bool, allowed_dirs: list<string>, short_aliases: bool}, security: array{access_roles: list<string>, access_checker: ?string, allow_unauthenticated: bool, export_roles: list<string>, apply_roles: list<string>, prune_roles: list<string>, providers: array<string, array{export_roles: list<string>|null, apply_roles: list<string>|null, prune_roles: list<string>|null}>}} $config */
        $p      = self::P;
        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $events = new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $clock  = new Reference('clock');

        $kinds = [];
        foreach ($config['scope_kinds'] as $kind => $definition) {
            $kinds[(string) $kind] = $definition['requires_id'];
        }
        $reader  = $config['reader'];
        $version = InstalledVersions::isInstalled('nowo-tech/seed-kit-bundle') ? (InstalledVersions::getPrettyVersion('nowo-tech/seed-kit-bundle') ?? 'dev') : 'dev';
        $container->setDefinition(SeedKitSettings::class, new Definition(SeedKitSettings::class, [
            $kinds,
            $config['apply']['batch_size'],
            $config['apply']['lease_seconds'],
            $config['validation']['max_errors'],
            $config['plan']['preview_rows'],
            $config['prune']['max_ratio'],
            'seed-kit ' . $version,
            $reader['max_depth'],
            $reader['max_nodes_per_item'],
            $reader['max_string_bytes'],
            $reader['max_properties'],
        ]));
        $container->setDefinition(ReaderLimits::class, new Definition(ReaderLimits::class, [
            $reader['max_bytes'],
            $reader['max_tree_bytes'],
            $reader['max_items'],
            $reader['max_documents'],
            $reader['max_depth'],
            $reader['max_line_bytes'],
        ]));

        $container->registerAttributeForAutoconfiguration(AsSeedProvider::class, static function (ChildDefinition $definition, AsSeedProvider $attribute): void {
            $definition->addTag(SeedProviderPass::TAG, ['name' => $attribute->name, 'depends_on' => $attribute->dependsOn]);
        });
        $container->setDefinition(ProviderRegistry::class, new Definition(ProviderRegistry::class, [new ServiceLocatorArgument([]), []]))->setPublic(true);

        $container->setDefinition(OpisSchemaValidator::class, (new Definition(OpisSchemaValidator::class))->addTag('kernel.reset', ['method' => 'reset']));
        $container->setAlias(SchemaValidatorInterface::class, OpisSchemaValidator::class);
        $container->setDefinition(DocumentReader::class, new Definition(DocumentReader::class))->setPublic(true);
        $container->setDefinition(DocumentWriter::class, new Definition(DocumentWriter::class));
        $container->setAlias(DocumentWriterInterface::class, DocumentWriter::class);
        $container->setDefinition(UuidV7IdGenerator::class, new Definition(UuidV7IdGenerator::class));
        $container->setAlias(IdGeneratorInterface::class, UuidV7IdGenerator::class);

        self::storage($container, $config['doctrine'], $config['transaction']['max_replays'], $logger, $clock);
        self::ports($container, $config, $logger);

        $secrets = new Reference($p . 'secret_validator', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setDefinition(ItemNormaliser::class, new Definition(ItemNormaliser::class, [new Reference(SchemaValidatorInterface::class), new Reference(SeedKitSettings::class), $secrets]));
        $container->setDefinition(Planner::class, new Definition(Planner::class, [new Reference(ProviderRegistry::class), new Reference(ItemNormaliser::class), new Reference(SeedKitSettings::class)]));
        $container->setDefinition(Applier::class, (new Definition(Applier::class, [
            new Reference(Planner::class),
            new Reference(WriteRunnerInterface::class),
            new Reference(LeaseStoreInterface::class),
            new Reference(RunStoreInterface::class),
            new Reference(IdGeneratorInterface::class),
            new Reference(SeedKitSettings::class),
            $clock,
            $logger,
            $events,
        ]))->addTag('monolog.logger', ['channel' => 'seed_kit']));
        $container->setDefinition(Exporter::class, new Definition(Exporter::class, [new Reference(DocumentWriterInterface::class), new Reference(SeedKitSettings::class), $clock, $secrets]));
        $container->setDefinition(SchemaPublisher::class, new Definition(SchemaPublisher::class, [$reader['max_items']]));
        $container->setDefinition(SchemaLinter::class, new Definition(SchemaLinter::class))->setPublic(true);
        $container->setDefinition(SeedOperations::class, (new Definition(SeedOperations::class, [
            new Reference(ProviderRegistry::class),
            new Reference(Planner::class),
            new Reference(Applier::class),
            new Reference(Exporter::class),
            new Reference(SchemaPublisher::class),
            new Reference(SeedScopeResolverInterface::class),
            new Reference(SeedAccessGateInterface::class),
            new Reference(SeedAuditSinkInterface::class),
            new Reference(SeedKitSettings::class),
            $logger,
            $events,
        ]))->setPublic(true)->addTag('monolog.logger', ['channel' => 'seed_kit']));
        $container->setDefinition(RunMaintenance::class, new Definition(RunMaintenance::class, [new Reference(RunStoreInterface::class), $clock, $config['runs']['history_days']]))->setPublic(true);
        $container->setDefinition(SchemaLintWarmer::class, (new Definition(SchemaLintWarmer::class, [new Reference(ProviderRegistry::class), new Reference(SchemaLinter::class), $logger]))
            ->addTag('kernel.cache_warmer')
            ->addTag('monolog.logger', ['channel' => 'seed_kit']));

        self::commands($container, $config['console']);
    }

    /**
     * Entity manager, connection, tables, stores, write unit, mapping listener.
     *
     * @param array{entity_manager: ?string, table_prefix: string, mapping: string} $doctrine
     */
    private static function storage(ContainerBuilder $container, array $doctrine, int $maxReplays, Reference $logger, Reference $clock): void
    {
        $p       = self::P;
        $manager = $doctrine['entity_manager'];
        $container->setAlias($p . 'entity_manager', is_string($manager) && $manager !== '' ? sprintf('doctrine.orm.%s_entity_manager', $manager) : 'doctrine.orm.entity_manager');
        $container->setDefinition($p . 'connection', (new Definition('Doctrine\DBAL\Connection'))->setFactory([new Reference($p . 'entity_manager'), 'getConnection']));
        $container->setDefinition(TableNames::class, new Definition(TableNames::class, [$doctrine['table_prefix']]));
        $container->setDefinition(DbalRunStore::class, new Definition(DbalRunStore::class, [new Reference($p . 'connection'), new Reference(TableNames::class), $clock]));
        $container->setAlias(RunStoreInterface::class, DbalRunStore::class);
        $container->setDefinition(DbalLeaseStore::class, new Definition(DbalLeaseStore::class, [new Reference($p . 'connection'), new Reference(TableNames::class), $clock]));
        $container->setAlias(LeaseStoreInterface::class, DbalLeaseStore::class);
        $container->setDefinition($p . 'write_unit', (new Definition(WriteUnit::class, [
            new Definition(ConnectionOnlyRegistry::class, [new Reference($p . 'connection')]),
            new Definition(WriteUnitPolicy::class, [$maxReplays]),
            null,
            $clock,
            $logger,
            null,
            new Reference('nowo_transaction_doctrine.commit_ledger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->addTag('monolog.logger', ['channel' => 'seed_kit']));
        $container->setDefinition(DbalWriteRunner::class, new Definition(DbalWriteRunner::class, [
            new Reference($p . 'write_unit'),
            (new Definition(DoctrineTransactionRunner::class))->setFactory([DoctrineTransactionRunner::class, 'forConnection'])->setArguments([new Reference($p . 'connection'), 1, $logger]),
        ]));
        $container->setAlias(WriteRunnerInterface::class, DbalWriteRunner::class);
        $container->setDefinition(SchemaMetadataListener::class, (new Definition(SchemaMetadataListener::class, [$doctrine['table_prefix']]))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
    }

    /**
     * Scope and actor resolvers, access checker, guard, voter, audit sink, secret validator.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidArgumentException
     */
    private static function ports(ContainerBuilder $container, array $config, Reference $logger): void
    {
        /** @var array{scope_resolver: ?string, actor_resolver: ?string, secrets: array{validator: ?string, vault_bridge: array{enabled: bool, scope_format: string}}, audit: array{auditkit: bool, sink: ?string, log_level: string}, security: array{access_roles: list<string>, access_checker: ?string, allow_unauthenticated: bool, export_roles: list<string>, apply_roles: list<string>, prune_roles: list<string>, providers: array<string, array{export_roles: list<string>|null, apply_roles: list<string>|null, prune_roles: list<string>|null}>}} $config */
        $p      = self::P;
        $tokens = new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        $container->setDefinition(PlatformScopeResolver::class, new Definition(PlatformScopeResolver::class));
        $container->setAlias(SeedScopeResolverInterface::class, $config['scope_resolver'] ?? PlatformScopeResolver::class);
        $container->setDefinition(SecurityActorResolver::class, new Definition(SecurityActorResolver::class, [$tokens]));
        $container->setAlias(SeedActorResolverInterface::class, $config['actor_resolver'] ?? SecurityActorResolver::class);

        $security = $config['security'];
        $roles    = [];
        foreach ($security['providers'] as $name => $override) {
            $roles[(string) $name] = ['export' => $override['export_roles'], 'apply' => $override['apply_roles'], 'prune' => $override['prune_roles']];
        }
        $container->setDefinition(ConfigurableSeedKitAccessChecker::class, new Definition(ConfigurableSeedKitAccessChecker::class, [
            $security['access_roles'],
            ['export' => $security['export_roles'], 'apply' => $security['apply_roles'], 'prune' => $security['prune_roles']],
            $roles,
            new Reference('security.role_hierarchy', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $container->setDefinition(AllowAllSeedKitAccessChecker::class, new Definition(AllowAllSeedKitAccessChecker::class));
        $checker = match ($security['access_checker']) {
            null        => ConfigurableSeedKitAccessChecker::class,
            'allow_all' => AllowAllSeedKitAccessChecker::class,
            default     => $security['access_checker'],
        };
        $container->setAlias(SeedKitAccessCheckerInterface::class, $checker);
        $container->setDefinition(SeedKitGuard::class, new Definition(SeedKitGuard::class, [
            new Reference(SeedKitAccessCheckerInterface::class),
            new Reference('event_dispatcher'),
            $security['allow_unauthenticated'],
            false,
            $tokens,
        ]))->setPublic(true);
        $container->setAlias(SeedAccessGateInterface::class, SeedKitGuard::class);
        if (class_exists(Voter::class)) {
            $container->setDefinition(SeedKitVoter::class, (new Definition(SeedKitVoter::class, [new Reference(SeedAccessGateInterface::class)]))->addTag('security.voter'));
        }

        $container->setDefinition(PsrLogAuditSink::class, (new Definition(PsrLogAuditSink::class, [$logger, $config['audit']['log_level']]))->addTag('monolog.logger', ['channel' => 'seed_kit']));
        $sink = PsrLogAuditSink::class;
        if ($config['audit']['auditkit'] && interface_exists(ActionLogRecorderInterface::class)) {
            $container->setDefinition(AuditKitSeedAuditSink::class, new Definition(AuditKitSeedAuditSink::class, [new Reference(ActionLogRecorderInterface::class)]));
            $sink = AuditKitSeedAuditSink::class;
        }
        $container->setAlias(SeedAuditSinkInterface::class, $config['audit']['sink'] ?? $sink);

        $secrets = $config['secrets'];
        if ($secrets['vault_bridge']['enabled']) {
            Requirement::check(interface_exists(VaultItemQueryInterface::class), 'nowo_seed_kit.secrets.vault_bridge needs nowo-tech/vault-bundle.');
            $container->setDefinition(VaultSecretReferenceValidator::class, new Definition(VaultSecretReferenceValidator::class, [
                new Reference(VaultItemQueryInterface::class),
                new Reference(VaultAccessCheckInterface::class),
                $secrets['vault_bridge']['scope_format'],
            ]));
            $container->setAlias($p . 'secret_validator', VaultSecretReferenceValidator::class);
        } elseif ($secrets['validator'] !== null) {
            $container->setAlias($p . 'secret_validator', $secrets['validator']);
        }
    }

    /**
     * The console commands (canonical names, plus `seed:*` aliases when `console.short_aliases`).
     *
     * @param array{require_actor: bool, allowed_dirs: list<string>, short_aliases: bool} $console
     */
    private static function commands(ContainerBuilder $container, array $console): void
    {
        $container->setDefinition(ConsolePaths::class, new Definition(ConsolePaths::class, [$console['allowed_dirs']]));
        $container->setDefinition(CommandSupport::class, new Definition(CommandSupport::class, [
            new Reference(SeedActorResolverInterface::class),
            new Reference(DocumentReader::class),
            new Reference(ReaderLimits::class),
            new Reference(ConsolePaths::class),
            $console['require_actor'],
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $support = new Reference(CommandSupport::class);
        foreach (self::COMMANDS as $class => [$name, $alias]) {
            $arguments = match ($class) {
                RunsListCommand::class, RunsPurgeCommand::class, ActorForgetCommand::class => [new Reference(RunMaintenance::class), $support],
                LintCommand::class                                                         => [new Reference(ProviderRegistry::class), new Reference(SchemaLinter::class), $support],
                default                                                                    => [new Reference(SeedOperations::class), $support],
            };
            $container->setDefinition($class, (new Definition($class, $arguments))
                ->addTag('console.command', ['command' => $console['short_aliases'] ? $name . '|' . $alias : $name]));
        }
    }
}
