<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Infrastructure\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nowo\SeedKitBundle\Domain\Model\ActorRef;
use Nowo\SeedKitBundle\Domain\Model\DocumentHash;
use Nowo\SeedKitBundle\Domain\Model\PlanHash;
use Nowo\SeedKitBundle\Domain\Model\RunId;
use Nowo\SeedKitBundle\Domain\Model\ScopeId;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Model\SeedScope;
use Nowo\SeedKitBundle\Domain\Port\RunStoreInterface;
use Nowo\SeedKitBundle\Domain\Run\Channel;
use Nowo\SeedKitBundle\Domain\Run\RunRecord;
use Nowo\SeedKitBundle\Domain\Run\RunStatus;
use Psr\Clock\ClockInterface;

use function array_is_list;
use function count;
use function implode;
use function is_array;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * The run history in `<prefix>seed_run` through DBAL on the bundle's connection (contract section 11): bound
 * parameters only, instants as UTC `Y-m-d H:i:s`, JSON columns written as text. 0.1 never stores a document (console
 * runs keep metadata only; the `document` column is for the 0.2 UI). Purge and actor forget work in id batches.
 */
final readonly class DbalRunStore implements RunStoreInterface
{
    /** Columns read back. */
    private const COLUMNS = 'id, scope_kind, scope_id, actor_ref, channel, status, providers, prune, document_hash, plan_hash, batch_digests, batches_done, counts, error_code, created_at, finished_at';

    /** Statuses a purge may delete. */
    private const FINAL = ['applied', 'partially_applied', 'failed', 'discarded', 'expired'];

    /**
     * Builds the store.
     */
    public function __construct(private Connection $connection, private TableNames $tables, private ClockInterface $clock)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function create(RunRecord $run): void
    {
        $now = $this->format($run->createdAt);
        $this->connection->insert($this->tables->run(), [
            'id'            => $run->id->value,
            'scope_kind'    => $run->scope->kind->value,
            'scope_id'      => $run->scope->storedId(),
            'actor_ref'     => $run->actor?->value,
            'channel'       => $run->channel->value,
            'status'        => $run->status->value,
            'providers'     => json_encode($run->providers, JSON_THROW_ON_ERROR),
            'prune'         => $run->prune,
            'document_hash' => $run->documentHash?->value,
            'plan_hash'     => $run->planHash->value,
            'batch_digests' => json_encode($run->batchDigests, JSON_THROW_ON_ERROR),
            'batches_done'  => $run->batchesDone,
            'counts'        => json_encode($run->counts, JSON_THROW_ON_ERROR),
            'error_code'    => $run->errorCode,
            'version'       => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ], ['prune' => ParameterType::BOOLEAN, 'batches_done' => ParameterType::INTEGER, 'version' => ParameterType::INTEGER]);
    }

    /**
     * {@inheritDoc}
     */
    public function markBatch(RunId $run, int $number, array $counts): bool
    {
        return $this->connection->executeStatement(
            sprintf('UPDATE %s SET batches_done = ?, counts = ?, updated_at = ?, version = version + 1 WHERE id = ? AND batches_done = ?', $this->tables->run()),
            [$number, json_encode($counts, JSON_THROW_ON_ERROR), $this->format($this->clock->now()), $run->value, $number - 1],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER],
        ) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function finish(RunId $run, RunStatus $status, ?string $errorCode): void
    {
        $now = $this->format($this->clock->now());
        $this->connection->executeStatement(
            sprintf('UPDATE %s SET status = ?, error_code = ?, finished_at = ?, updated_at = ?, document = NULL, version = version + 1 WHERE id = ?', $this->tables->run()),
            [$status->value, $errorCode, $now, $now, $run->value],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function find(RunId $run): ?RunRecord
    {
        $row = $this->connection->fetchAssociative(sprintf('SELECT %s FROM %s WHERE id = ?', self::COLUMNS, $this->tables->run()), [$run->value]);

        return is_array($row) ? $this->record($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null): array
    {
        $where  = [];
        $params = [];
        if ($scope instanceof SeedScope) {
            $where[]  = 'scope_kind = ? AND scope_id = ?';
            $params[] = $scope->kind->value;
            $params[] = $scope->storedId();
        }
        if ($status instanceof RunStatus) {
            $where[]  = 'status = ?';
            $params[] = $status->value;
        }
        if ($before instanceof RunId) {
            $where[]  = 'id < ?';
            $params[] = $before->value;
        }
        $sql  = sprintf('SELECT %s FROM %s%s ORDER BY id DESC', self::COLUMNS, $this->tables->run(), $where === [] ? '' : ' WHERE ' . implode(' AND ', $where));
        $rows = $this->connection->fetchAllAssociative($this->connection->getDatabasePlatform()->modifyLimitQuery($sql, $limit), $params);
        $runs = [];
        foreach ($rows as $row) {
            $runs[] = $this->record($row);
        }

        return $runs;
    }

    /**
     * {@inheritDoc}
     */
    public function purge(DateTimeImmutable $before, int $batchSize): int
    {
        $deleted = 0;
        do {
            $ids = $this->connection->fetchFirstColumn(
                $this->connection->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id FROM %s WHERE created_at < ? AND status IN (?) ORDER BY id', $this->tables->run()), $batchSize),
                [$this->format($before), self::FINAL],
                [ParameterType::STRING, ArrayParameterType::STRING],
            );
            if ($ids !== []) {
                $deleted += (int) $this->connection->executeStatement(sprintf('DELETE FROM %s WHERE id IN (?)', $this->tables->run()), [$ids], [ArrayParameterType::STRING]);
            }
        } while (count($ids) === $batchSize);

        return $deleted;
    }

    /**
     * {@inheritDoc}
     */
    public function countPurgeable(DateTimeImmutable $before): int
    {
        $count = $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE created_at < ? AND status IN (?)', $this->tables->run()),
            [$this->format($before), self::FINAL],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetActor(ActorRef $actor, int $batchSize): int
    {
        $changed = 0;
        do {
            $ids = $this->connection->fetchFirstColumn(
                $this->connection->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id FROM %s WHERE actor_ref = ? ORDER BY id', $this->tables->run()), $batchSize),
                [$actor->value],
            );
            if ($ids !== []) {
                $changed += (int) $this->connection->executeStatement(sprintf('UPDATE %s SET actor_ref = NULL WHERE id IN (?)', $this->tables->run()), [$ids], [ArrayParameterType::STRING]);
            }
        } while (count($ids) === $batchSize);

        return $changed;
    }

    /**
     * A record from a row.
     *
     * @param array<string, mixed> $row
     */
    private function record(array $row): RunRecord
    {
        $scopeId = is_string($row['scope_id']) && $row['scope_id'] !== '' ? new ScopeId($row['scope_id']) : null;

        return new RunRecord(
            new RunId(self::text($row['id'])),
            new SeedScope(new ScopeKind(self::text($row['scope_kind'])), $scopeId),
            is_string($row['actor_ref']) ? new ActorRef($row['actor_ref']) : null,
            Channel::from(self::text($row['channel'])),
            RunStatus::from(self::text($row['status'])),
            $this->intMap($row['providers']),
            (bool) $row['prune'],
            is_string($row['document_hash']) ? new DocumentHash($row['document_hash']) : null,
            new PlanHash(self::text($row['plan_hash'])),
            $this->stringList($row['batch_digests']),
            is_numeric($row['batches_done']) ? (int) $row['batches_done'] : 0,
            $this->intMap($row['counts']),
            is_string($row['error_code']) ? $row['error_code'] : null,
            new DateTimeImmutable(self::text($row['created_at']), new DateTimeZone('UTC')),
            is_string($row['finished_at']) ? new DateTimeImmutable($row['finished_at'], new DateTimeZone('UTC')) : null,
        );
    }

    /**
     * A JSON object of integers.
     *
     * @return array<string, int>
     */
    private function intMap(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;
        $out   = [];
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                if (is_int($entry)) {
                    $out[(string) $key] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * A JSON list of strings.
     *
     * @return list<string>
     */
    private function stringList(mixed $json): array
    {
        $value = is_string($json) ? json_decode($json, true) : null;
        $out   = [];
        if (is_array($value) && array_is_list($value)) {
            foreach ($value as $entry) {
                if (is_string($entry)) {
                    $out[] = $entry;
                }
            }
        }

        return $out;
    }

    /**
     * A scalar column as text ('' otherwise).
     */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A UTC instant string.
     */
    private function format(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
