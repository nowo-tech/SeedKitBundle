<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Kernel;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function in_array;
use function str_starts_with;

/**
 * Test overrides applied after every extension: the MockClock as `clock`, and every service of the bundle (and a few
 * framework ones the tests read) public, so they survive the removal of unused private services.
 */
final class TestOverridesPass implements CompilerPassInterface
{
    /** Framework services the tests read. */
    private const PUBLIC = ['event_dispatcher', 'security.token_storage', 'security.authorization_checker', 'translator', 'doctrine.dbal.default_connection', 'doctrine.dbal.seed_connection'];

    public function process(ContainerBuilder $container): void
    {
        $container->setAlias('clock', 'test.mock_clock')->setPublic(true);
        foreach ($container->getDefinitions() as $id => $definition) {
            if ($this->exposed($id)) {
                $definition->setPublic(true);
            }
        }
        foreach ($container->getAliases() as $id => $alias) {
            if ($this->exposed($id)) {
                $alias->setPublic(true);
            }
        }
    }

    private function exposed(string $id): bool
    {
        return str_starts_with($id, 'nowo_seed_kit.') || str_starts_with($id, 'Nowo\\SeedKitBundle\\') || in_array($id, self::PUBLIC, true);
    }
}
