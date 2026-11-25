<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Support;

use Doctrine\Common\EventManager;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;

use function dirname;
use function getenv;
use function is_dir;
use function is_numeric;
use function is_string;
use function mkdir;
use function sys_get_temp_dir;

use const PHP_VERSION_ID;

/**
 * The test database: `NOWO_TEST_DATABASE_URL` (CI matrix: MySQL 8.4, MariaDB 11.4, PostgreSQL 17) or a SQLite file.
 * Builds connections (optionally with the statement log), an entity manager with the bundle's mapping (XML or
 * attributes) and the schema listener, and the host tables of the DB-backed fixture providers.
 */
final class TestDatabase
{
    /** Entity namespace. */
    public const ENTITIES = 'Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity';

    /** Host tables of the fixture providers. */
    private const HOST_TABLES = [
        'host_tag'     => 'scope_key VARCHAR(160) NOT NULL, slug VARCHAR(64) NOT NULL, label VARCHAR(120) NOT NULL, color VARCHAR(16) NULL, api_token VARCHAR(210) NULL, created_at VARCHAR(40) NOT NULL, PRIMARY KEY (scope_key, slug)',
        'host_article' => 'scope_key VARCHAR(160) NOT NULL, code VARCHAR(32) NOT NULL, title VARCHAR(200) NOT NULL, tag VARCHAR(64) NULL, level INT NULL, owner VARCHAR(64) NULL, PRIMARY KEY (scope_key, code)',
        'host_note'    => 'scope_key VARCHAR(160) NOT NULL, code VARCHAR(32) NOT NULL, body VARCHAR(500) NOT NULL, PRIMARY KEY (scope_key, code)',
    ];

    /**
     * The database URL.
     */
    public static function url(): string
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');
        if (is_string($url) && $url !== '') {
            return $url;
        }
        $dir = sys_get_temp_dir() . '/nowo-seed-kit-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return 'sqlite:///' . $dir . '/test.sqlite';
    }

    /**
     * Whether the database is a real server (not SQLite).
     */
    public static function isServer(): bool
    {
        $url = getenv('NOWO_TEST_DATABASE_URL');

        return is_string($url) && $url !== '';
    }

    /**
     * A new connection, optionally logging its statements.
     */
    public static function connection(?QueryLog $log = null): Connection
    {
        $params = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql', 'sqlite' => 'pdo_sqlite']))->parse(self::url());
        $config = new DbalConfiguration();
        if ($log instanceof QueryLog) {
            $config->setMiddlewares([new QueryLogMiddleware($log)]);
        }

        return DriverManager::getConnection($params, $config);
    }

    /**
     * An entity manager on the connection with the bundle's mapping.
     */
    public static function entityManager(Connection $connection, string $mapping = 'xml', string $prefix = 'nowo_'): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl($mapping === 'xml'
            ? new SimplifiedXmlDriver([dirname(__DIR__, 2) . '/src/Resources/config/doctrine' => self::ENTITIES])
            : new AttributeDriver([dirname(__DIR__, 2) . '/src/Infrastructure/Doctrine/Entity']));
        $config->setProxyDir(sys_get_temp_dir() . '/nowo-seed-kit-proxies');
        $config->setProxyNamespace('NowoSeedKitProxies');
        $config->setAutoGenerateProxyClasses(true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $events = new EventManager();
        $events->addEventListener([Events::loadClassMetadata], new SchemaMetadataListener($prefix));

        return new EntityManager($connection, $config, $events);
    }

    /**
     * Drops and creates the bundle's tables of the entity manager.
     */
    public static function reset(EntityManager $entityManager): void
    {
        $tool    = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }

    /**
     * A fresh database: connection, entity manager, empty bundle and host tables.
     *
     * @return array{0: Connection, 1: EntityManager}
     */
    public static function fresh(string $mapping = 'xml', string $prefix = 'nowo_', ?QueryLog $log = null): array
    {
        $connection = self::connection($log);
        $manager    = self::entityManager($connection, $mapping, $prefix);
        self::reset($manager);
        self::createHostTables($connection);

        return [$connection, $manager];
    }

    /**
     * Drops and creates the host tables of the fixture providers.
     */
    public static function createHostTables(Connection $connection): void
    {
        foreach (self::HOST_TABLES as $table => $columns) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
            $connection->executeStatement('CREATE TABLE ' . $table . ' (' . $columns . ')');
        }
    }

    /**
     * The platform family: `mysql`, `mariadb`, `postgresql` or `sqlite`.
     */
    public static function family(Connection $connection): string
    {
        $platform = $connection->getDatabasePlatform();

        return match (true) {
            $platform instanceof MariaDBPlatform       => 'mariadb',
            $platform instanceof AbstractMySQLPlatform => 'mysql',
            $platform instanceof PostgreSQLPlatform    => 'postgresql',
            default                                    => 'sqlite',
        };
    }

    /**
     * A numeric column value as an integer.
     */
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : -1;
    }

    /**
     * Makes a connection give up a lock wait after about one second.
     */
    public static function shortLockWait(Connection $connection): void
    {
        $connection->executeStatement(self::family($connection) === 'postgresql' ? "SET lock_timeout = '1000ms'" : 'SET SESSION innodb_lock_wait_timeout = 1');
    }
}
