<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Kernel;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\AccessListener;
use Nowo\SeedKitBundle\Tests\Fixtures\Host\HostHooks;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function sys_get_temp_dir;

/**
 * Boots {@see TestKernel} with a configuration, creates the bundle's and the host's tables and runs commands. The
 * compiled containers of a previous run are removed once per class, so the extension, the configuration tree and the
 * compiler pass really run (coverage).
 */
abstract class KernelTestCase extends TestCase
{
    protected ?TestKernel $kernel = null;

    public static function setUpBeforeClass(): void
    {
        $files = new Filesystem();
        $files->remove(sys_get_temp_dir() . '/nowo-seed-kit-kernel');
        $files->remove(TestKernel::allowedDir());
        $files->mkdir(TestKernel::allowedDir());
    }

    protected function tearDown(): void
    {
        $this->kernel?->shutdown();
        $this->kernel = null;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, bool> $features
     * @param 'ddd'|'first'|'named'|'standard' $host
     */
    protected function boot(array $config = [], array $features = [], string $host = 'standard', bool $schema = true): ContainerInterface
    {
        $this->kernel?->shutdown();
        $this->kernel = new TestKernel($config, $features, $host);
        $this->kernel->boot();
        if ($schema) {
            $manager = $this->container()->get('nowo_seed_kit.entity_manager');
            self::assertInstanceOf(EntityManagerInterface::class, $manager);
            $tool    = new SchemaTool($manager);
            $classes = $manager->getMetadataFactory()->getAllMetadata();
            $tool->dropSchema($classes);
            $tool->createSchema($classes);
            TestDatabase::createHostTables($this->connection());
        }

        return $this->container();
    }

    /**
     * The test container of the booted kernel.
     *
     * @phpstan-impure
     */
    protected function container(): ContainerInterface
    {
        self::assertNotNull($this->kernel);
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    /**
     * A service of the expected class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $id, string $class): object
    {
        $service = $this->container()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * The bundle's connection (also the providers' one).
     */
    protected function connection(): Connection
    {
        return $this->service('nowo_seed_kit.connection', Connection::class);
    }

    protected function listener(): AccessListener
    {
        return $this->service('test.access_listener', AccessListener::class);
    }

    protected function hooks(): HostHooks
    {
        return $this->service('test.hooks', HostHooks::class);
    }

    /**
     * Runs a command.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $options CommandTester options (interactive, capture_stderr_separately, verbosity)
     * @param list<string> $answers interactive answers
     */
    protected function command(string $name, array $input = [], array $options = [], array $answers = []): CommandTester
    {
        self::assertNotNull($this->kernel);
        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find($name));
        if ($answers !== []) {
            $tester->setInputs($answers);
        }
        $tester->execute($input, $options + ['interactive' => false]);

        return $tester;
    }

    /**
     * Writes a file in the allowed directory and returns its path.
     */
    protected function file(string $name, string $content): string
    {
        $path = TestKernel::allowedDir() . '/' . $name;
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Inserts a host tag row.
     */
    protected function insertTag(string $slug, string $label, ?string $color = null, ?string $token = null, string $scope = 'platform'): void
    {
        $this->connection()->insert('host_tag', ['scope_key' => $scope, 'slug' => $slug, 'label' => $label, 'color' => $color, 'api_token' => $token, 'created_at' => '2026-01-01']);
    }

    /**
     * Inserts a host article row.
     */
    protected function insertArticle(string $code, string $title, ?string $owner = null, ?int $level = null, ?string $tag = null, string $scope = 'platform'): void
    {
        $this->connection()->insert('host_article', ['scope_key' => $scope, 'code' => $code, 'title' => $title, 'tag' => $tag, 'level' => $level, 'owner' => $owner]);
    }
}
