<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler;

use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Domain\Exception\InvalidValueException;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

use function array_keys;
use function array_shift;
use function count;
use function implode;
use function is_array;
use function is_string;
use function ksort;
use function sort;
use function sprintf;

use const SORT_STRING;

/**
 * Collects the providers (tag `nowo_seed_kit.provider` with `name` and optional `depends_on`, or `#[AsSeedProvider]`)
 * when the container is compiled (contract section 6): valid and unique names, dependencies that exist, no cycle
 * (the build fails and names the cycle), then the dependency order with Kahn's algorithm (ties by name byte order).
 * The registry gets a lazy service locator and the order.
 */
final class SeedProviderPass implements CompilerPassInterface
{
    /** The provider tag. */
    public const TAG = 'nowo_seed_kit.provider';

    /**
     * {@inheritDoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(ProviderRegistry::class)) {
            return;
        }
        $services = [];
        $depends  = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                $tag  = is_array($tag) ? $tag : [];
                $name = $tag['name'] ?? null;
                if (!is_string($name)) {
                    throw new InvalidArgumentException(sprintf('Seed provider "%s": the tag "%s" needs a "name" attribute (use #[AsSeedProvider(name: ...)]).', $id, self::TAG));
                }
                try {
                    new ProviderName($name);
                } catch (InvalidValueException) {
                    throw new InvalidArgumentException(sprintf('Seed provider "%s": "%s" is not a valid provider name.', $id, $name));
                }
                if (isset($services[$name])) {
                    throw new InvalidArgumentException(sprintf('Seed provider name "%s" is registered twice.', $name));
                }
                $services[$name] = new Reference($id);
                $list            = $tag['depends_on'] ?? [];
                $depends[$name]  = [];
                foreach (is_array($list) ? $list : [$list] as $dependency) {
                    if (is_string($dependency)) {
                        $depends[$name][] = $dependency;
                    }
                }
            }
        }
        $order = self::order($depends);
        ksort($services);
        $container->getDefinition(ProviderRegistry::class)
            ->setArgument(0, ServiceLocatorTagPass::register($container, $services))
            ->setArgument(1, $order);
    }

    /**
     * The dependency order (Kahn, ties by name byte order).
     *
     * @param array<string, list<string>> $depends
     *
     * @throws InvalidArgumentException for an unknown dependency or a cycle
     *
     * @return list<string>
     */
    public static function order(array $depends): array
    {
        $incoming = [];
        $after    = [];
        foreach ($depends as $name => $list) {
            $incoming[$name] ??= 0;
            foreach ($list as $dependency) {
                if (!isset($depends[$dependency])) {
                    throw new InvalidArgumentException(sprintf('Seed provider "%s" depends on "%s", which is not registered.', $name, $dependency));
                }
                ++$incoming[$name];
                $after[$dependency][] = $name;
            }
        }
        $ready = [];
        foreach ($incoming as $name => $count) {
            if ($count === 0) {
                $ready[] = (string) $name;
            }
        }
        $order = [];
        while ($ready !== []) {
            sort($ready, SORT_STRING);
            $name    = array_shift($ready);
            $order[] = $name;
            foreach ($after[$name] ?? [] as $next) {
                if (--$incoming[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }
        if (count($order) !== count($depends)) {
            $left = [];
            foreach (array_keys($incoming) as $name) {
                if ($incoming[$name] > 0) {
                    $left[] = (string) $name;
                }
            }
            sort($left, SORT_STRING);

            throw new InvalidArgumentException(sprintf('Seed providers depend on each other in a cycle: %s.', implode(', ', $left)));
        }

        return $order;
    }
}
