<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle;

use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\SeedProviderPass;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\ConfigurationTree;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\ServiceDefinitions;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Form\FormProfile;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function array_key_exists;
use function array_key_first;
use function is_array;
use function is_dir;
use function is_string;

/**
 * Seed data for Symfony: one provider per kind of data; export an empty template or the current rows, edit the file
 * (by hand or with an AI reading the published JSON Schema), dry-run it as a diff with a plan hash, and apply it in
 * idempotent batches through the host's own handlers, with leases, access checks and audit.
 *
 * 0.1 ships the core, the console commands and the provider contract test kit; 0.2 adds the opt-in UI (Import / export
 * pages per scope kind, upload dialog, diff, apply, runs), the Messenger asynchronous apply and the Scheduler purge.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoSeedKitBundle extends AbstractBundle
{
    /** Configuration root and service / parameter prefix. */
    public const ALIAS = 'nowo_seed_kit';

    /** Translation domain. */
    public const TRANSLATION_DOMAIN = 'NowoSeedKitBundle';

    /** Twig namespace of the templates (REQ-TWIG-002): `@NowoSeedKitBundle/...`, overrides in `templates/bundles/NowoSeedKitBundle/`. */
    public const TWIG_NAMESPACE = 'NowoSeedKitBundle';

    /** FormKit profile of the bundle's forms. */
    public const FORM_PROFILE = FormProfile::NAME;

    /** Doctrine mapping name. */
    public const MAPPING_NAME = 'NowoSeedKitBundle';

    protected string $extensionAlias = self::ALIAS;

    /**
     * The bundle's resources live in `src/Resources`.
     */
    public function getPath(): string
    {
        return __DIR__;
    }

    /**
     * Adds the provider pass and the CSRF guard of the pages.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new SeedProviderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
        $container->addCompilerPass(new CsrfProtectionPass());
    }

    /**
     * The strict configuration tree (contract section 15).
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        ConfigurationTree::define($definition->rootNode());
    }

    /**
     * Registers the read-only mapping of the bundle tables (XML by default, attributes on request) on the configured
     * entity manager, so schema tools, migrations and a host's drift gate see them.
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->prependUi($builder);
        if (!$builder->hasExtension('doctrine')) {
            return;
        }
        $manager = null;
        $mapping = 'xml';
        foreach ($builder->getExtensionConfig(self::ALIAS) as $config) {
            $doctrine = is_array($config) && is_array($config['doctrine'] ?? null) ? $config['doctrine'] : [];
            if (array_key_exists('entity_manager', $doctrine)) {
                $manager = is_string($doctrine['entity_manager']) && $doctrine['entity_manager'] !== '' ? $doctrine['entity_manager'] : null;
            }
            if (($doctrine['mapping'] ?? null) === 'attribute') {
                $mapping = 'attribute';
            }
        }
        $manager ??= $this->defaultEntityManager($builder);
        $builder->prependExtensionConfig('doctrine', ['orm' => ['entity_managers' => [$manager => ['mappings' => [self::MAPPING_NAME => [
            'type'      => $mapping,
            'dir'       => $mapping === 'xml' ? __DIR__ . '/Resources/config/doctrine' : __DIR__ . '/Infrastructure/Doctrine/Entity',
            'prefix'    => 'Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity',
            'is_bundle' => false,
        ]]]]]]);
    }

    /**
     * The Twig namespace `NowoSeedKitBundle` (host overrides in `templates/bundles/NowoSeedKitBundle/` first, then the
     * bundle copy, also reachable as `@NowoSeedKit`) and the FormKit profile `seed_kit` when the host does not declare it.
     */
    private function prependUi(ContainerBuilder $builder): void
    {
        $views = __DIR__ . '/Resources/views';
        if ($builder->hasExtension('twig')) {
            $paths   = [];
            $project = $builder->hasParameter('kernel.project_dir') ? $builder->getParameter('kernel.project_dir') : null;
            if (is_string($project) && is_dir($project . '/templates/bundles/' . self::TWIG_NAMESPACE)) {
                $paths[$project . '/templates/bundles/' . self::TWIG_NAMESPACE] = self::TWIG_NAMESPACE;
            }
            $paths[$views] = self::TWIG_NAMESPACE;
            $builder->prependExtensionConfig('twig', ['paths' => $paths]);
        }
        if ($builder->hasExtension('nowo_form_kit')) {
            $declared = false;
            foreach ($builder->getExtensionConfig('nowo_form_kit') as $config) {
                $declared = $declared || (is_array($config) && is_array($config['profiles'] ?? null) && array_key_exists(self::FORM_PROFILE, $config['profiles']));
            }
            if (!$declared) {
                $builder->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_PROFILE => [
                    'alias'              => self::FORM_PROFILE,
                    'translation_domain' => self::TRANSLATION_DOMAIN,
                    'defaults'           => ['attr' => ['class' => 'nowo-ui-input'], 'row_attr' => []],
                ]]]);
            }
        }
    }

    /**
     * Registers the services.
     *
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        ServiceDefinitions::register($builder, $config);
    }

    /**
     * The host's default entity manager name: `doctrine.orm.default_entity_manager`, else the first declared one, else
     * `default` (DoctrineBundle's own rule).
     */
    private function defaultEntityManager(ContainerBuilder $builder): string
    {
        $first = null;
        foreach ($builder->getExtensionConfig('doctrine') as $doctrine) {
            $orm = is_array($doctrine) && is_array($doctrine['orm'] ?? null) ? $doctrine['orm'] : [];
            if (is_string($orm['default_entity_manager'] ?? null) && $orm['default_entity_manager'] !== '') {
                return $orm['default_entity_manager'];
            }
            if ($first === null && is_array($orm['entity_managers'] ?? null) && $orm['entity_managers'] !== []) {
                $first = (string) array_key_first($orm['entity_managers']);
            }
        }

        return $first ?? 'default';
    }
}
