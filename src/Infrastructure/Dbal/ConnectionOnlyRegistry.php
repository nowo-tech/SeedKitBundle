<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use InvalidArgumentException;

/**
 * A registry holding the bundle's connection and **no entity manager**, given to transaction-doctrine's write unit:
 * its replay then rolls back the connection's leftover levels and never clears, resets or flushes an entity manager
 * (the bundle writes through DBAL only; REQ-RUNTIME-003 rule 2, contract F5). Its "inside a transaction" check reads
 * this connection only.
 */
final readonly class ConnectionOnlyRegistry implements ManagerRegistry
{
    /** The name of the single connection. */
    public const NAME = 'nowo_seed_kit';

    /**
     * Wraps the connection.
     */
    public function __construct(private Connection $connection)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultConnectionName(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnection(?string $name = null): Connection
    {
        return $this->connection;
    }

    /**
     * {@inheritDoc}
     */
    public function getConnections(): array
    {
        return [self::NAME => $this->connection];
    }

    /**
     * {@inheritDoc}
     */
    public function getConnectionNames(): array
    {
        return [self::NAME => self::NAME];
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultManagerName(): string
    {
        return self::NAME;
    }

    /**
     * {@inheritDoc}
     */
    public function getManager(?string $name = null): ObjectManager
    {
        throw new InvalidArgumentException('The seed kit write unit has no entity manager.');
    }

    /**
     * {@inheritDoc}
     */
    public function getManagers(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function resetManager(?string $name = null): ObjectManager
    {
        throw new InvalidArgumentException('The seed kit write unit has no entity manager.');
    }

    /**
     * {@inheritDoc}
     */
    public function getManagerNames(): array
    {
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function getRepository(string $persistentObject, ?string $persistentManagerName = null): ObjectRepository
    {
        throw new InvalidArgumentException('The seed kit write unit has no entity manager.');
    }

    /**
     * {@inheritDoc}
     */
    public function getManagerForClass(string $class): ?ObjectManager
    {
        return null;
    }
}
