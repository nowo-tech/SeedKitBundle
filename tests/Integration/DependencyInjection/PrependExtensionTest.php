<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use Nowo\SeedKitBundle\NowoSeedKitBundle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

use function array_key_first;
use function count;
use function dirname;
use function is_array;
use function sys_get_temp_dir;

/**
 * The prepended Doctrine mapping (contract section 11): XML by default or attributes, on the configured entity manager
 * or the host's default one (`default_entity_manager`, else the first declared, else `default`).
 */
final class PrependExtensionTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $seedKit
     * @param list<array<string, mixed>> $doctrine
     *
     * @return array<string, mixed>|null the prepended Doctrine configuration, or null when nothing was prepended
     */
    private function prepend(array $seedKit, array $doctrine, bool $withDoctrine = true): ?array
    {
        $builder = new ContainerBuilder();
        $builder->setParameter('kernel.environment', 'test');
        $builder->setParameter('kernel.build_dir', sys_get_temp_dir());
        $bundle = new NowoSeedKitBundle();
        $own    = $bundle->getContainerExtension();
        self::assertInstanceOf(PrependExtensionInterface::class, $own);
        $builder->registerExtension($own);
        if ($withDoctrine) {
            $builder->registerExtension(new DoctrineExtension());
            foreach ($doctrine as $config) {
                $builder->loadFromExtension('doctrine', $config);
            }
        }
        foreach ($seedKit as $config) {
            $builder->loadFromExtension('nowo_seed_kit', $config);
        }
        $before = $withDoctrine ? count($builder->getExtensionConfig('doctrine')) : 0;
        $own->prepend($builder);
        if (!$withDoctrine) {
            return null;
        }
        $configs = $builder->getExtensionConfig('doctrine');
        if (count($configs) === $before) {
            return null;
        }

        return $configs[0];
    }

    /**
     * @param array<string, mixed>|null $prepended
     *
     * @return array{string, array<string, mixed>}
     */
    private function mapping(?array $prepended): array
    {
        self::assertIsArray($prepended);
        $orm = $prepended['orm'] ?? null;
        self::assertIsArray($orm);
        $managers = $orm['entity_managers'] ?? null;
        self::assertIsArray($managers);
        $manager = (string) array_key_first($managers);
        $entry   = $managers[$manager];
        self::assertIsArray($entry);
        self::assertIsArray($entry['mappings'] ?? null);
        $mapping = $entry['mappings'][NowoSeedKitBundle::MAPPING_NAME] ?? null;
        self::assertTrue(is_array($mapping));
        $typed = [];
        foreach ($mapping as $key => $value) {
            $typed[(string) $key] = $value;
        }
        $mapping = $typed;

        return [$manager, $mapping];
    }

    #[Test]
    public function withoutDoctrineNothingIsPrepended(): void
    {
        self::assertNull($this->prepend([[]], [], false));
    }

    #[Test]
    public function xmlMappingOnTheDefaultManagerByDefault(): void
    {
        [$manager, $mapping] = $this->mapping($this->prepend([[]], [['dbal' => ['url' => 'sqlite:///:memory:']]]));

        self::assertSame('default', $manager);
        self::assertSame('xml', $mapping['type']);
        self::assertSame(dirname(__DIR__, 3) . '/src/Resources/config/doctrine', $mapping['dir']);
        self::assertSame('Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity', $mapping['prefix']);
        self::assertFalse($mapping['is_bundle']);
    }

    #[Test]
    public function attributeMappingOnRequest(): void
    {
        [, $mapping] = $this->mapping($this->prepend([['doctrine' => ['mapping' => 'xml']], ['doctrine' => ['mapping' => 'attribute']]], []));

        self::assertSame('attribute', $mapping['type']);
        self::assertSame(dirname(__DIR__, 3) . '/src/Infrastructure/Doctrine/Entity', $mapping['dir']);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, list<array<string, mixed>>, string}>
     */
    public static function managers(): iterable
    {
        yield 'configured manager' => [[['doctrine' => ['entity_manager' => 'seed']]], [['orm' => ['default_entity_manager' => 'main']]], 'seed'];
        yield 'later empty manager resets to the default' => [[['doctrine' => ['entity_manager' => 'seed']], ['doctrine' => ['entity_manager' => '']]], [['orm' => ['default_entity_manager' => 'main']]], 'main'];
        yield 'named default manager' => [[[]], [['orm' => ['entity_managers' => ['first' => []]]], ['orm' => ['default_entity_manager' => 'main']]], 'main'];
        yield 'first declared manager' => [[['doctrine' => null]], [['orm' => ['default_entity_manager' => '', 'entity_managers' => ['primary' => [], 'secondary' => []]]], ['orm' => ['entity_managers' => ['other' => []]]]], 'primary'];
        yield 'no orm section' => [[], [['dbal' => []], ['orm' => 'invalid']], 'default'];
    }

    /**
     * @param list<array<string, mixed>> $seedKit
     * @param list<array<string, mixed>> $doctrine
     */
    #[Test]
    #[DataProvider('managers')]
    public function theManagerIsDetected(array $seedKit, array $doctrine, string $expected): void
    {
        [$manager] = $this->mapping($this->prepend($seedKit, $doctrine));

        self::assertSame($expected, $manager);
    }
}
