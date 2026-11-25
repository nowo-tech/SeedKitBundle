<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\DependencyInjection;

use Nowo\SeedKitBundle\NowoSeedKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;

/**
 * The strict configuration tree (contract section 15, REQ-SF-006): defaults, ranges and the cross-field rules.
 */
final class ConfigurationTreeTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function process(array $input): array
    {
        $extension = (new NowoSeedKitBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);
        $config = [];
        foreach ((new Processor())->processConfiguration($configuration, [$input]) as $key => $value) {
            $config[(string) $key] = $value;
        }

        return $config;
    }

    /**
     * A nested value of the processed configuration.
     *
     * @param array<string, mixed> $config
     */
    private function at(array $config, string ...$path): mixed
    {
        $value = $config;
        foreach ($path as $key) {
            self::assertIsArray($value);
            self::assertArrayHasKey($key, $value);
            $value = $value[$key];
        }

        return $value;
    }

    #[Test]
    public function defaultsFollowTheContract(): void
    {
        $config = $this->process([]);

        self::assertSame(['platform' => ['requires_id' => false]], $config['scope_kinds']);
        self::assertNull($config['scope_resolver']);
        self::assertNull($config['actor_resolver']);
        self::assertSame(['entity_manager' => null, 'table_prefix' => 'nowo_', 'mapping' => 'xml'], $config['doctrine']);
        self::assertSame(['batch_size' => 500, 'lease_seconds' => 300], $config['apply']);
        self::assertSame(['max_replays' => 3], $config['transaction']);
        self::assertSame(['max_ratio' => 0.5], $config['prune']);
        self::assertSame(['history_days' => 365], $config['runs']);
        self::assertSame(['validator' => null, 'vault_bridge' => ['enabled' => false, 'scope_format' => '{kind}:{id}']], $config['secrets']);
        self::assertSame(['auditkit' => true, 'sink' => null, 'log_level' => 'notice'], $config['audit']);
        self::assertTrue($this->at($config, 'console', 'require_actor'));
        self::assertTrue($this->at($config, 'console', 'short_aliases'));
        self::assertSame(['%kernel.project_dir%/config/seeds', '%kernel.project_dir%/var/seeds'], $this->at($config, 'console', 'allowed_dirs'));
        self::assertSame(['ROLE_ADMIN'], $this->at($config, 'security', 'access_roles'));
        self::assertSame([], $this->at($config, 'security', 'prune_roles'));
        self::assertFalse($this->at($config, 'security', 'allow_unauthenticated'));
        self::assertSame([], $this->at($config, 'security', 'providers'));
    }

    #[Test]
    public function scopeKindsDefaultToRequiringAnId(): void
    {
        $config = $this->process(['scope_kinds' => ['platform' => ['requires_id' => false], 'organization' => []]]);

        self::assertSame(['platform' => ['requires_id' => false], 'organization' => ['requires_id' => true]], $config['scope_kinds']);
    }

    #[Test]
    public function roleOverridesAcceptNullOrAList(): void
    {
        $config = $this->process(['security' => ['providers' => ['crm.contacts' => ['export_roles' => ['ROLE_CRM'], 'apply_roles' => null, 'prune_roles' => []]]]]);

        self::assertEquals(['export_roles' => ['ROLE_CRM'], 'apply_roles' => null, 'prune_roles' => []], $this->at($config, 'security', 'providers', 'crm.contacts'));
    }

    #[Test]
    public function allowAllWithAllowUnauthenticatedIsAccepted(): void
    {
        $config = $this->process(['security' => ['access_checker' => 'allow_all', 'allow_unauthenticated' => true]]);

        self::assertSame('allow_all', $this->at($config, 'security', 'access_checker'));
    }

    #[Test]
    public function aValidatorWithoutTheVaultBridgeIsAccepted(): void
    {
        self::assertSame('app.secrets', $this->at($this->process(['secrets' => ['validator' => 'app.secrets']]), 'secrets', 'validator'));
        self::assertSame('', $this->at($this->process(['doctrine' => ['table_prefix' => '']]), 'doctrine', 'table_prefix'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'allow_all without allow_unauthenticated' => [['security' => ['access_checker' => 'allow_all']], 'allow_all needs allow_unauthenticated'];
        yield 'validator and vault bridge' => [['secrets' => ['validator' => 'app.secrets', 'vault_bridge' => ['enabled' => true]]], 'set either validator or vault_bridge.enabled'];
        yield 'prefix with a capital' => [['doctrine' => ['table_prefix' => 'Nowo_']], 'table_prefix must match'];
        yield 'prefix too long' => [['doctrine' => ['table_prefix' => 'abcdefghijklmnopq']], 'table_prefix must match'];
        yield 'prefix not a string' => [['doctrine' => ['table_prefix' => 12]], 'table_prefix must match'];
        yield 'no scope kind' => [['scope_kinds' => []], 'at least one kind'];
        yield 'bad scope kind' => [['scope_kinds' => ['Org' => ['requires_id' => true]]], 'at least one kind'];
        yield 'bad mapping' => [['doctrine' => ['mapping' => 'yaml']], 'mapping'];
        yield 'batch size above range' => [['apply' => ['batch_size' => 5001]], 'batch_size'];
        yield 'prune ratio below range' => [['prune' => ['max_ratio' => 0.0]], 'max_ratio'];
        yield 'unknown key' => [['ui' => ['enabled' => true]], 'Unrecognized option "ui"'];
        yield 'role override not a list' => [['security' => ['providers' => ['a.b' => ['export_roles' => ['x' => 'ROLE_A']]]]], 'export_roles must be null or a list'];
        yield 'role override not strings' => [['security' => ['providers' => ['a.b' => ['apply_roles' => [1]]]]], 'apply_roles must be null or a list'];
        yield 'role override a string' => [['security' => ['providers' => ['a.b' => ['prune_roles' => 'ROLE_A']]]], 'prune_roles must be null or a list'];
        yield 'bad log level' => [['audit' => ['log_level' => 'emergency']], 'log_level'];
    }

    /**
     * @param array<string, mixed> $input
     */
    #[Test]
    #[DataProvider('invalid')]
    public function invalidConfigurationFailsTheBuild(array $input, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($input);
    }
}
