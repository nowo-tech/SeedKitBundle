<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\Persistence\Mapping\RuntimeReflectionService;
use Doctrine\Persistence\ObjectManager;
use InvalidArgumentException;
use Nowo\SeedKitBundle\Infrastructure\Dbal\ConnectionOnlyRegistry;
use Nowo\SeedKitBundle\Infrastructure\Dbal\DbalSql;
use Nowo\SeedKitBundle\Infrastructure\Dbal\TableNames;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity\SeedLease;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\Entity\SeedRun;
use Nowo\SeedKitBundle\Infrastructure\Doctrine\SchemaMetadataListener;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_keys;
use function dirname;

/**
 * Platform branches checked without a server (the real MySQL / MariaDB / PostgreSQL behaviour is in the Database
 * suite): insert-or-ignore SQL, the collation listener, the connection-only registry, the read-only entities.
 */
final class PlatformBranchesTest extends TestCase
{
    /**
     * A connection whose platform is the given one and whose statements are recorded.
     *
     * @param list<array{string, list<mixed>, list<mixed>}> $statements
     */
    private function connection(AbstractPlatform $platform, array &$statements = []): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = [], array $types = []) use (&$statements): int {
            $statements[] = [$sql, $params, $types];

            return 1;
        });

        return $connection;
    }

    #[Test]
    public function insertIgnoreUsesThePlatformSyntax(): void
    {
        $statements = [];
        self::assertSame(1, DbalSql::insertIgnore($this->connection(new MySQL84Platform(), $statements), 'nowo_seed_lease', ['provider' => 'a.b', 'run_id' => '', 'n' => 3], ['provider', 'scope_kind']));
        self::assertSame(1, DbalSql::insertIgnore($this->connection(new PostgreSQLPlatform(), $statements), 'nowo_seed_lease', ['provider' => 'a.b'], ['provider', 'scope_kind']));

        self::assertSame([
            ['INSERT INTO nowo_seed_lease (provider, run_id, n) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE provider = provider', ['a.b', '', 3], [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]],
            ['INSERT INTO nowo_seed_lease (provider) VALUES (?) ON CONFLICT (provider, scope_kind) DO NOTHING', ['a.b'], [ParameterType::STRING]],
        ], $statements);
    }

    /**
     * The attribute metadata of an entity, before any listener.
     *
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private static function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->initializeReflection(new RuntimeReflectionService());
        (new AttributeDriver([dirname(__DIR__, 3) . '/src/Infrastructure/Doctrine/Entity']))->loadMetadataForClass($class, $metadata);

        return $metadata;
    }

    /**
     * @param ClassMetadata<object> $metadata
     */
    private function load(ClassMetadata $metadata, AbstractPlatform $platform, string $prefix = 'nowo_'): void
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection($platform));
        (new SchemaMetadataListener($prefix))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $manager));
    }

    #[Test]
    public function mariadbAndMysqlGetBinaryNoPadIdentifiers(): void
    {
        $run = self::metadata(SeedRun::class);
        $this->load($run, new MariaDBPlatform(), 'acme_');
        self::assertSame('acme_seed_run', $run->getTableName());
        self::assertSame(['acme_seed_run_scope_idx', 'acme_seed_run_status_idx', 'acme_seed_run_actor_idx'], array_keys($run->table['indexes'] ?? []));
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_nopad_bin'], $run->fieldMappings['actorRef']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_nopad_bin', 'default' => ''], $run->fieldMappings['scopeId']->options);
        self::assertNull($run->fieldMappings['counts']->options, 'not an identifier');

        $lease = self::metadata(SeedLease::class);
        $this->load($lease, new MySQL84Platform());
        self::assertSame('nowo_seed_lease', $lease->getTableName());
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $lease->fieldMappings['provider']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $lease->fieldMappings['runId']->options);
    }

    #[Test]
    public function postgresqlKeepsItsExactCollationAndOtherEntitiesAreLeftAlone(): void
    {
        $run = self::metadata(SeedRun::class);
        $this->load($run, new PostgreSQLPlatform(), '');
        self::assertSame('seed_run', $run->getTableName());
        self::assertNull($run->fieldMappings['actorRef']->options);

        $other = new ClassMetadata(stdClass::class);
        $other->setPrimaryTable(['name' => 'host_table']);
        $this->load($other, new MariaDBPlatform());
        self::assertSame('host_table', $other->getTableName());
    }

    #[Test]
    public function theWriteUnitRegistryHoldsOnlyTheConnection(): void
    {
        $connection = $this->createStub(Connection::class);
        $registry   = new ConnectionOnlyRegistry($connection);

        self::assertSame('nowo_seed_kit', $registry->getDefaultConnectionName());
        self::assertSame($connection, $registry->getConnection());
        self::assertSame($connection, $registry->getConnection('other'));
        self::assertSame(['nowo_seed_kit' => $connection], $registry->getConnections());
        self::assertSame(['nowo_seed_kit' => 'nowo_seed_kit'], $registry->getConnectionNames());
        self::assertSame('nowo_seed_kit', $registry->getDefaultManagerName());
        self::assertSame([], $registry->getManagers());
        self::assertSame([], $registry->getManagerNames());
        self::assertNull($registry->getManagerForClass(SeedRun::class));
        foreach ([
            static fn (): ObjectManager => $registry->getManager(),
            static fn (): ObjectManager => $registry->resetManager(),
            static fn (): object => $registry->getRepository(SeedRun::class),
        ] as $call) {
            try {
                $call();
                self::fail('a manager was returned');
            } catch (InvalidArgumentException $e) {
                self::assertSame('The seed kit write unit has no entity manager.', $e->getMessage());
            }
        }
    }

    #[Test]
    public function tableNamesCarryThePrefix(): void
    {
        self::assertSame('nowo_seed_run', (new TableNames())->run());
        self::assertSame('seed_lease', (new TableNames(''))->lease());
    }

    #[Test]
    public function theEntitiesAreReadOnlyMappingsOfTheRows(): void
    {
        $at    = new DateTimeImmutable('2026-11-24 10:00:00');
        $run   = new SeedRun('id', 'platform', '', 'alice', 'console', 'applied', ['a.b' => 1], false, null, 'sk1:x', [], 1, ['create' => 1], null, null, 2, $at, $at, null, $at);
        $lease = new SeedLease('a.b', 'platform', '', 'id', $at);

        self::assertSame(['id', 'alice', 2, 'a.b'], [$run->id, $run->actorRef, $run->version, $lease->provider]);
        self::assertSame($at, $lease->lockedUntil);
        self::assertSame($at, $run->finishedAt);
    }
}
