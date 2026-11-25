<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\DependencyInjection;

use Nowo\SeedKitBundle\Application\ProviderRegistry;
use Nowo\SeedKitBundle\Infrastructure\Symfony\DependencyInjection\Compiler\SeedProviderPass;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\MinimalProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * The provider pass (contract section 6): names, duplicates, unknown dependencies, cycles and the Kahn order.
 */
final class SeedProviderPassTest extends TestCase
{
    /**
     * @param array<string, list<array<string, mixed>>> $providers service id => tags
     */
    private function container(array $providers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ProviderRegistry::class, new Definition(ProviderRegistry::class, [new ServiceLocatorArgument([]), []]));
        foreach ($providers as $id => $tags) {
            $definition = new Definition(MinimalProvider::class);
            foreach ($tags as $tag) {
                $definition->addTag(SeedProviderPass::TAG, $tag);
            }
            $container->setDefinition($id, $definition);
        }

        return $container;
    }

    #[Test]
    public function withoutTheRegistryNothingHappens(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('p', (new Definition(MinimalProvider::class))->addTag(SeedProviderPass::TAG));

        (new SeedProviderPass())->process($container);

        self::assertFalse($container->hasDefinition(ProviderRegistry::class));
    }

    #[Test]
    public function theRegistryGetsALocatorAndTheDependencyOrder(): void
    {
        $container = $this->container([
            'app.contacts'  => [['name' => 'crm.contacts', 'depends_on' => ['crm.companies', 'crm.tags', 42]]],
            'app.companies' => [['name' => 'crm.companies', 'depends_on' => 'crm.tags']],
            'app.tags'      => [['name' => 'crm.tags']],
            'app.alpha'     => [['name' => 'a.alpha', 'depends_on' => null]],
        ]);

        (new SeedProviderPass())->process($container);

        $registry = $container->getDefinition(ProviderRegistry::class);
        self::assertSame(['a.alpha', 'crm.tags', 'crm.companies', 'crm.contacts'], $registry->getArgument(1));
        $locator = $registry->getArgument(0);
        self::assertInstanceOf(Reference::class, $locator);
    }

    #[Test]
    public function kahnBreaksTiesByNameByteOrder(): void
    {
        self::assertSame(['B', 'a', 'b', 'c'], SeedProviderPass::order(['c' => ['a'], 'b' => [], 'a' => [], 'B' => []]));
        self::assertSame(['x', 'y', 'z'], SeedProviderPass::order(['z' => ['y'], 'y' => ['x'], 'x' => []]));
        self::assertSame([], SeedProviderPass::order([]));
    }

    #[Test]
    public function aTagWithoutANameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed provider "app.nameless": the tag "nowo_seed_kit.provider" needs a "name" attribute');

        (new SeedProviderPass())->process($this->container(['app.nameless' => [[]]]));
    }

    #[Test]
    public function anInvalidNameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed provider "app.bad": "Bad Name" is not a valid provider name.');

        (new SeedProviderPass())->process($this->container(['app.bad' => [['name' => 'Bad Name']]]));
    }

    #[Test]
    public function aDuplicateNameFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed provider name "crm.tags" is registered twice.');

        (new SeedProviderPass())->process($this->container(['app.one' => [['name' => 'crm.tags']], 'app.two' => [['name' => 'crm.tags']]]));
    }

    #[Test]
    public function anUnknownDependencyFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed provider "crm.contacts" depends on "crm.companies", which is not registered.');

        (new SeedProviderPass())->process($this->container(['app.contacts' => [['name' => 'crm.contacts', 'depends_on' => ['crm.companies']]]]));
    }

    #[Test]
    public function aCycleFailsAndNamesItsMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Seed providers depend on each other in a cycle: a.one, a.three, a.two.');

        SeedProviderPass::order(['a.one' => ['a.three'], 'a.two' => ['a.one'], 'a.three' => ['a.two'], 'a.free' => []]);
    }
}
