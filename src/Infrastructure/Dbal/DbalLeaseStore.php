<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\LeaseStoreInterface;
use Psr\Clock\ClockInterface;

use function is_array;
use function is_string;
use function max;
use function sprintf;

/**
 * The (provider, scope) lease in `<prefix>seed_lease` (contract section 10.3): the row is inserted on first use
 * (insert-or-ignore, no error, so a PostgreSQL transaction is never aborted), then claimed by one conditional
 * `UPDATE … WHERE locked_until < now OR run_id = ?` and read back (MySQL counts changed rows, not matched ones). Each
 * statement commits on its own; no transaction is held between batches. A crashed holder's lease expires after the TTL.
 */
final readonly class DbalLeaseStore implements LeaseStoreInterface
{
    /** An expired lease. */
    private const EPOCH = '1970-01-01 00:00:00';

    /**
     * Builds the store.
     */
    public function __construct(private Connection $connection, private TableNames $tables, private ClockInterface $clock)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function acquire(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): int
    {
        $key = [$provider->value, $scope->kind->value, $scope->storedId()];
        DbalSql::insertIgnore($this->connection, $this->tables->lease(), ['provider' => $key[0], 'scope_kind' => $key[1], 'scope_id' => $key[2], 'run_id' => '', 'locked_until' => self::EPOCH], ['provider', 'scope_kind', 'scope_id']);
        $now = $this->now();
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET locked_until = ?, run_id = ? WHERE provider = ? AND scope_kind = ? AND scope_id = ? AND (locked_until < ? OR run_id = ?)', $this->tables->lease()),
            [$this->format($now->modify('+' . $ttlSeconds . ' seconds')), $run->value, ...$key, $this->format($now), $run->value],
        );
        $row = $this->connection->fetchAssociative(sprintf('SELECT run_id, locked_until FROM %s WHERE provider = ? AND scope_kind = ? AND scope_id = ?', $this->tables->lease()), $key);
        if (!is_array($row) || $row['run_id'] === $run->value) {
            return 0;
        }
        $until = $row['locked_until'];
        $left  = is_string($until) ? (new DateTimeImmutable($until, new DateTimeZone('UTC')))->getTimestamp() - $now->getTimestamp() : $ttlSeconds;

        return max(1, $left);
    }

    /**
     * {@inheritDoc}
     */
    public function renew(ProviderName $provider, SeedScope $scope, RunId $run, int $ttlSeconds): bool
    {
        $key = [$provider->value, $scope->kind->value, $scope->storedId()];
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET locked_until = ? WHERE provider = ? AND scope_kind = ? AND scope_id = ? AND run_id = ?', $this->tables->lease()),
            [$this->format($this->now()->modify('+' . $ttlSeconds . ' seconds')), ...$key, $run->value],
        );

        return $this->connection->fetchOne(sprintf('SELECT run_id FROM %s WHERE provider = ? AND scope_kind = ? AND scope_id = ?', $this->tables->lease()), $key) === $run->value;
    }

    /**
     * {@inheritDoc}
     */
    public function release(ProviderName $provider, SeedScope $scope, RunId $run): void
    {
        $this->connection->executeStatement(
            sprintf("UPDATE %s SET locked_until = ?, run_id = '' WHERE provider = ? AND scope_kind = ? AND scope_id = ? AND run_id = ?", $this->tables->lease()),
            [self::EPOCH, $provider->value, $scope->kind->value, $scope->storedId(), $run->value],
        );
    }

    /**
     * Now in UTC, second precision.
     */
    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * A UTC instant string.
     */
    private function format(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
