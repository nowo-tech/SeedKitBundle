<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;

use function str_starts_with;

/**
 * Completes the bundle's mapping when Doctrine loads it (contract section 11): the table and index names get the
 * configured prefix (REQ-ORM-002), and on MySQL / MariaDB the identifier columns (ids, provider, scope kind and id, actor refs,
 * status, hashes) are binary NO PAD (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`), so `Bob` never matches `bob `
 * (REQ-SEC-006). PostgreSQL and SQLite compare exactly by default. Schema tools and a host's drift gate therefore see the tables
 * exactly as the bundle creates them.
 */
final readonly class SchemaMetadataListener
{
    /** Namespace of the bundle's entities. */
    private const ENTITY_NAMESPACE = 'Nowo\\SeedKitBundle\\Infrastructure\\Doctrine\\Entity\\';

    /** Exact-key columns (by field name). */
    private const EXACT_FIELDS = ['id', 'scopeKind', 'scopeId', 'actorRef', 'channel', 'status', 'documentHash', 'planHash', 'errorCode', 'provider', 'runId'];

    /**
     * @param string $prefix the table prefix (`doctrine.table_prefix`)
     */
    public function __construct(private string $prefix)
    {
    }

    /**
     * Applies the prefix and the collations to the bundle's entities.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        if (!str_starts_with($metadata->getName(), self::ENTITY_NAMESPACE)) {
            return;
        }
        $this->prefix($metadata);

        $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        $collation = $platform instanceof MariaDBPlatform ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin';
        foreach (self::EXACT_FIELDS as $field) {
            if (isset($metadata->fieldMappings[$field])) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => $collation] + ($metadata->fieldMappings[$field]->options ?? []);
            }
        }
    }

    /**
     * Prefixes the table, index and unique-constraint names.
     *
     * @param ClassMetadata<object> $metadata
     */
    private function prefix(ClassMetadata $metadata): void
    {
        $table         = $metadata->table;
        $table['name'] = $this->prefix . $metadata->getTableName();
        foreach (['indexes', 'uniqueConstraints'] as $kind) {
            $renamed = [];
            foreach ($table[$kind] ?? [] as $name => $definition) {
                $renamed[$this->prefix . $name] = $definition;
            }
            if ($renamed !== []) {
                $table[$kind] = $renamed;
            }
        }
        $metadata->table = $table;
    }
}
