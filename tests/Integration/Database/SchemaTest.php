<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\SeedKitBundle\Tests\Support\TestDatabase;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function implode;
use function in_array;
use function ksort;
use function str_repeat;

/**
 * The bundle tables on the real database (contract section 11): prefixed table and index names (REQ-ORM-002), exact
 * identifier columns (REQ-SEC-006: binary NO PAD on MySQL / MariaDB), and the XML and attribute mappings giving the
 * same schema.
 */
final class SchemaTest extends DatabaseTestCase
{
    #[Test]
    public function tablesAndIndexesCarryThePrefix(): void
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();
        self::assertContains('nowo_seed_run', $tables);
        self::assertContains('nowo_seed_lease', $tables);
        $indexes = array_keys($this->connection->createSchemaManager()->listTableIndexes('nowo_seed_run'));
        foreach (['nowo_seed_run_scope_idx', 'nowo_seed_run_status_idx', 'nowo_seed_run_actor_idx'] as $index) {
            self::assertTrue(in_array($index, $indexes, true), $index . ' in ' . implode(', ', $indexes));
        }

        [$connection] = TestDatabase::fresh('xml', '');
        self::assertContains('seed_run', $connection->createSchemaManager()->listTableNames());
        $connection->close();
    }

    #[Test]
    public function identifierColumnsCompareExactly(): void
    {
        $this->connection->insert('nowo_seed_run', [
            'id'         => '01936f2a-0000-7000-8000-000000000001', 'scope_kind' => 'platform', 'scope_id' => '', 'actor_ref' => 'Bob', 'channel' => 'console', 'status' => 'applied',
            'providers'  => '{}', 'prune' => 0, 'plan_hash' => 'sk1:' . str_repeat('c', 64), 'batch_digests' => '[]', 'batches_done' => 0, 'counts' => '{}', 'version' => 1,
            'created_at' => '2026-11-24 10:00:00', 'updated_at' => '2026-11-24 10:00:00',
        ]);

        foreach (['Bob' => 1, 'bob' => 0, 'Bob ' => 0, 'BOB' => 0] as $actor => $rows) {
            self::assertSame($rows, TestDatabase::int($this->connection->fetchOne('SELECT COUNT(*) FROM nowo_seed_run WHERE actor_ref = ?', [$actor])), '"' . $actor . '"');
        }

        $family = TestDatabase::family($this->connection);
        if ($family === 'mysql' || $family === 'mariadb') {
            $collations = $this->connection->fetchAllKeyValue("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_seed_run' AND COLLATION_NAME IS NOT NULL");
            $expected   = $family === 'mariadb' ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
            foreach (['id', 'scope_kind', 'scope_id', 'actor_ref', 'channel', 'status', 'document_hash', 'plan_hash', 'error_code'] as $column) {
                self::assertSame($expected, $collations[$column] ?? null, $column);
            }
            $lease = $this->connection->fetchAllKeyValue("SELECT COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nowo_seed_lease' AND COLLATION_NAME IS NOT NULL");
            foreach (['provider', 'scope_kind', 'scope_id', 'run_id'] as $column) {
                self::assertSame($expected, $lease[$column] ?? null, $column);
            }
        }
    }

    #[Test]
    public function xmlAndAttributeMappingsGiveTheSameSchema(): void
    {
        $xml       = self::schema($this->entityManager);
        $attribute = self::schema(TestDatabase::entityManager($this->connection, 'attribute'));

        self::assertTrue($this->connection->createSchemaManager()->createComparator()->compareSchemas($xml, $attribute)->isEmpty());
        self::assertTrue($this->connection->createSchemaManager()->createComparator()->compareSchemas($attribute, $xml)->isEmpty());
        foreach (['nowo_seed_run', 'nowo_seed_lease'] as $table) {
            self::assertSame(self::columns($xml->getTable($table)), self::columns($attribute->getTable($table)), $table);
        }
    }

    private static function schema(EntityManagerInterface $manager): Schema
    {
        return (new SchemaTool($manager))->getSchemaFromMetadata($manager->getMetadataFactory()->getAllMetadata());
    }

    /**
     * Column definitions by name.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function columns(Table $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[$column->getName()] = $column->toArray();
        }
        ksort($columns);

        return $columns;
    }
}
