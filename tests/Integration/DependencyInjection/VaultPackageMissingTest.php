<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\DependencyInjection;

use Composer\Autoload\ClassLoader;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Requirement;
use Nowo\SeedKitBundle\NowoSeedKitBundle;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

use function interface_exists;
use function is_array;
use function spl_autoload_functions;
use function sys_get_temp_dir;

/**
 * `secrets.vault_bridge.enabled` without nowo-tech/vault-bundle fails the container build. The package is a dev
 * dependency, so the test hides it from the autoloader in a separate process.
 */
final class VaultPackageMissingTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    public function theBridgeNeedsTheVaultPackage(): void
    {
        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $function[0]->setPsr4('Nowo\VaultBundle\\', []);
            }
        }
        self::assertFalse(interface_exists('Nowo\VaultBundle\Service\VaultItemQueryInterface'));

        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $builder->setParameter('kernel.project_dir', sys_get_temp_dir());
        $extension = (new NowoSeedKitBundle())->getContainerExtension();
        self::assertNotNull($extension);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nowo_seed_kit.secrets.vault_bridge needs nowo-tech/vault-bundle.');

        $extension->load([['audit' => ['auditkit' => false], 'secrets' => ['vault_bridge' => ['enabled' => true]]]], $builder);
    }

    #[Test]
    public function aMetRequirementPasses(): void
    {
        Requirement::check(true, 'never shown');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needed');
        Requirement::check(false, 'needed');
    }
}
