<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Builder\VariableNodeDefinition;

use function array_is_list;
use function is_array;
use function is_string;
use function preg_match;

/**
 * The strict configuration tree of `nowo_seed_kit` (contract section 15, REQ-SF-006). Unknown keys are refused; ranges
 * and cross-field rules fail the container build. 0.1 holds only what 0.1 uses (no dead configuration): the `ui` and
 * `messenger` sections arrive with 0.2.
 */
final class ConfigurationTree
{
    /** Role list of the defaults. */
    private const ADMIN = ['ROLE_ADMIN'];

    /**
     * Defines the tree under the root node.
     */
    public static function define(ArrayNodeDefinition $root): void
    {
        $children = $root->children();
        self::scopes($children);
        self::doctrine($children);
        self::limits($children);
        self::features($children);
        self::security($children);
        $children->end();

        $root
            ->validate()
                ->ifTrue(static function (mixed $config): bool {
                    $security = is_array($config) && is_array($config['security'] ?? null) ? $config['security'] : [];

                    return ($security['access_checker'] ?? null) === 'allow_all' && ($security['allow_unauthenticated'] ?? false) !== true;
                })
                ->thenInvalid('nowo_seed_kit.security.access_checker: allow_all needs allow_unauthenticated: true (demos only).')
            ->end()
            ->validate()
                ->ifTrue(static function (mixed $config): bool {
                    $secrets = is_array($config) && is_array($config['secrets'] ?? null) ? $config['secrets'] : [];

                    return ($secrets['validator'] ?? null) !== null && is_array($secrets['vault_bridge'] ?? null) && ($secrets['vault_bridge']['enabled'] ?? false) === true;
                })
                ->thenInvalid('nowo_seed_kit.secrets: set either validator or vault_bridge.enabled, not both.')
            ->end();
    }

    /**
     * `scope_kinds`, `scope_resolver`, `actor_resolver`.
     */
    private static function scopes(NodeBuilder $children): void
    {
        $children
            ->arrayNode('scope_kinds')
                ->info('Scope kinds of the host (kind => {requires_id}); platform has no id.')
                ->useAttributeAsKey('kind')
                ->defaultValue(['platform' => ['requires_id' => false]])
                ->validate()
                    ->ifTrue(static function (mixed $kinds): bool {
                        if (!is_array($kinds) || $kinds === []) {
                            return true;
                        }
                        foreach ($kinds as $kind => $definition) {
                            if (preg_match('/\A[a-z][a-z0-9_]{0,31}\z/D', (string) $kind) !== 1) {
                                return true;
                            }
                        }

                        return false;
                    })
                    ->thenInvalid('nowo_seed_kit.scope_kinds: at least one kind, each matching [a-z][a-z0-9_]{0,31}; got %s.')
                ->end()
                ->arrayPrototype()
                    ->children()
                        ->booleanNode('requires_id')->defaultTrue()->end()
                    ->end()
                ->end()
            ->end()
            ->scalarNode('scope_resolver')
                ->info('Service id of a SeedScopeResolverInterface (default: platform scope only).')
                ->defaultNull()
            ->end()
            ->scalarNode('actor_resolver')
                ->info('Service id of a SeedActorResolverInterface (default: the security user identifier).')
                ->defaultNull()
            ->end();
    }

    /**
     * `doctrine.*`.
     */
    private static function doctrine(NodeBuilder $children): void
    {
        $children
            ->arrayNode('doctrine')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('entity_manager')
                        ->info('Entity manager whose connection holds the bundle tables and runs the batch transactions (null: the default one).')
                        ->defaultNull()
                    ->end()
                    ->scalarNode('table_prefix')
                        ->defaultValue('nowo_')
                        ->validate()
                            ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || preg_match('/\A([a-z][a-z0-9_]{0,15})?\z/D', $prefix) !== 1)
                            ->thenInvalid('nowo_seed_kit.doctrine.table_prefix must match ^[a-z][a-z0-9_]{0,15}$ or be empty; got %s.')
                        ->end()
                    ->end()
                    ->enumNode('mapping')->values(['xml', 'attribute'])->defaultValue('xml')->end()
                ->end()
            ->end();
    }

    /**
     * `reader.*`, `validation.*`, `apply.*`, `prune.*`, `plan.*`, `runs.*`, `transaction.*`.
     */
    private static function limits(NodeBuilder $children): void
    {
        $children
            ->arrayNode('reader')
                ->info('Caps of the document reader (contract section 13.3).')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('max_bytes')->min(1024)->defaultValue(268435456)->end()
                    ->integerNode('max_tree_bytes')->min(1024)->max(67108864)->defaultValue(4194304)->end()
                    ->integerNode('max_items')->min(1)->defaultValue(1000000)->end()
                    ->integerNode('max_documents')->min(1)->max(100)->defaultValue(20)->end()
                    ->integerNode('max_depth')->min(2)->max(64)->defaultValue(16)->end()
                    ->integerNode('max_string_bytes')->min(64)->defaultValue(65536)->end()
                    ->integerNode('max_properties')->min(1)->defaultValue(256)->end()
                    ->integerNode('max_line_bytes')->min(1024)->defaultValue(1048576)->end()
                    ->integerNode('max_nodes_per_item')->min(1)->defaultValue(10000)->end()
                ->end()
            ->end()
            ->arrayNode('validation')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('max_errors')->min(1)->max(10000)->defaultValue(200)->end()
                ->end()
            ->end()
            ->arrayNode('apply')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('batch_size')->min(1)->max(5000)->defaultValue(500)->end()
                    ->integerNode('lease_seconds')->min(10)->max(86400)->defaultValue(300)->end()
                ->end()
            ->end()
            ->arrayNode('transaction')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('max_replays')->info('Attempts of a batch write unit after a lock conflict.')->min(1)->max(10)->defaultValue(3)->end()
                ->end()
            ->end()
            ->arrayNode('prune')
                ->addDefaultsIfNotSet()
                ->children()
                    ->floatNode('max_ratio')->min(0.01)->max(1.0)->defaultValue(0.5)->end()
                ->end()
            ->end()
            ->arrayNode('plan')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('preview_rows')->min(1)->max(100000)->defaultValue(200)->end()
                ->end()
            ->end()
            ->arrayNode('runs')
                ->addDefaultsIfNotSet()
                ->children()
                    ->integerNode('history_days')->min(1)->max(3650)->defaultValue(365)->end()
                ->end()
            ->end();
    }

    /**
     * `secrets.*`, `audit.*`, `console.*`.
     */
    private static function features(NodeBuilder $children): void
    {
        $children
            ->arrayNode('secrets')
                ->addDefaultsIfNotSet()
                ->children()
                    ->scalarNode('validator')
                        ->info('Service id of a SecretReferenceValidatorInterface. Without one (and without the Vault bridge) every secret reference is refused on import and omitted on export.')
                        ->defaultNull()
                    ->end()
                    ->arrayNode('vault_bridge')
                        ->addDefaultsIfNotSet()
                        ->children()
                            ->booleanNode('enabled')->defaultFalse()->end()
                            ->scalarNode('scope_format')
                                ->info('Vault owner scope of a seed scope: {kind} and {id} are replaced (a scope without id uses {kind} alone).')
                                ->defaultValue('{kind}:{id}')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->arrayNode('audit')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('auditkit')->info('Record in AuditKit when nowo-tech/audit-kit-bundle is installed.')->defaultTrue()->end()
                    ->scalarNode('sink')->info('Service id of a SeedAuditSinkInterface (overrides the log and AuditKit sinks).')->defaultNull()->end()
                    ->enumNode('log_level')->values(['debug', 'info', 'notice', 'warning'])->defaultValue('notice')->end()
                ->end()
            ->end()
            ->arrayNode('console')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('require_actor')->info('Writing commands need --actor (else the actor is "system").')->defaultTrue()->end()
                    ->arrayNode('allowed_dirs')
                        ->info('Directories console file arguments must resolve into (unless --allow-any-path).')
                        ->scalarPrototype()->end()
                        ->defaultValue(['%kernel.project_dir%/config/seeds', '%kernel.project_dir%/var/seeds'])
                    ->end()
                    ->booleanNode('short_aliases')->info('Also register seed:* aliases of the nowo:seed-kit:* commands.')->defaultTrue()->end()
                ->end()
            ->end();
    }

    /**
     * `security.*` (house access pattern, REQ-UI-002).
     */
    private static function security(NodeBuilder $children): void
    {
        $children
            ->arrayNode('security')
                ->addDefaultsIfNotSet()
                ->children()
                    ->arrayNode('access_roles')->scalarPrototype()->end()->defaultValue(self::ADMIN)->end()
                    ->scalarNode('access_checker')
                        ->info('null (role-based default), "allow_all" (demos, needs allow_unauthenticated) or a SeedKitAccessCheckerInterface service id.')
                        ->defaultNull()
                    ->end()
                    ->booleanNode('allow_unauthenticated')->defaultFalse()->end()
                    ->arrayNode('export_roles')->scalarPrototype()->end()->defaultValue(self::ADMIN)->end()
                    ->arrayNode('apply_roles')->scalarPrototype()->end()->defaultValue(self::ADMIN)->end()
                    ->arrayNode('prune_roles')->info('Default []: nobody may prune until mapped (Q-SK-13).')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('providers')
                        ->info('Per-provider role overrides.')
                        ->useAttributeAsKey('name')
                        ->arrayPrototype()
                            ->children()
                                ->append(self::roleOverride('export_roles'))
                                ->append(self::roleOverride('apply_roles'))
                                ->append(self::roleOverride('prune_roles'))
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * A per-provider role list: null (not overridden) or a list of role names.
     */
    private static function roleOverride(string $name): VariableNodeDefinition
    {
        $node = new VariableNodeDefinition($name);
        $node
            ->defaultNull()
            ->validate()
                ->ifTrue(static function (mixed $roles): bool {
                    if ($roles === null) {
                        return false;
                    }
                    if (!is_array($roles) || !array_is_list($roles)) {
                        return true;
                    }
                    foreach ($roles as $role) {
                        if (!is_string($role)) {
                            return true;
                        }
                    }

                    return false;
                })
                ->thenInvalid('nowo_seed_kit.security.providers.*.' . $name . ' must be null or a list of role names; got %s.')
            ->end();

        return $node;
    }
}
