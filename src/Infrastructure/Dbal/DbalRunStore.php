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
use Nowo\SeedKitBundle\Domain\Run\BatchManifest;
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
use function is_resource;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function stream_get_contents;

use const JSON_THROW_ON_ERROR;

/**
 * The run history in `<prefix>seed_run` through DBAL on the bundle's connection (contract section 11): bound
 * parameters only, instants as UTC `Y-m-d H:i:s`, JSON columns written as text, the document of a UI run as a binary
 * large object (never read by the listing queries). Console runs keep metadata only. The run claim is a
 * compare-and-set on `status` + `version` (contract section 10.3). Purge, expiry and actor forget work in id batches.
 */
final readonly class DbalRunStore implements RunStoreInterface
{
    /** Columns read back. */
    private const COLUMNS = 'id, scope_kind, scope_id, actor_ref, channel, status, providers, prune, document_hash, plan_hash, batch_digests, batches_done, counts, error_code, created_at, finished_at, version, expires_at, updated_at, CASE WHEN document IS NULL THEN 0 ELSE 1 END AS has_document';

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
    public function create(RunRecord $run, ?string $document = null): void
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
            'batch_digests' => json_encode($run->manifest instanceof BatchManifest ? $run->manifest->toStored() : $run->batchDigests, JSON_THROW_ON_ERROR),
            'batches_done'  => $run->batchesDone,
            'counts'        => json_encode($run->counts, JSON_THROW_ON_ERROR),
            'error_code'    => $run->errorCode,
            'document'      => $document,
            'version'       => $run->version,
            'created_at'    => $now,
            'updated_at'    => $now,
            'expires_at'    => $run->expiresAt instanceof DateTimeImmutable ? $this->format($run->expiresAt) : null,
        ], ['prune' => ParameterType::BOOLEAN, 'batches_done' => ParameterType::INTEGER, 'version' => ParameterType::INTEGER, 'document' => ParameterType::LARGE_OBJECT]);
    }

    /**
     * {@inheritDoc}
     */
    public function document(RunId $run): ?string
    {
        $value = $this->connection->fetchOne(sprintf('SELECT document FROM %s WHERE id = ?', $this->tables->run()), [$run->value]);
        // PostgreSQL returns bytea as a stream.
        $value = is_resource($value) ? stream_get_contents($value) : $value;

        return is_string($value) ? $value : null;
    }

    /**
     * {@inheritDoc}
     */
    public function findPlanned(ActorRef $actor, SeedScope $scope, DocumentHash $document, PlanHash $plan, bool $prune, DateTimeImmutable $now): ?RunRecord
    {
        $sql = sprintf(
            'SELECT %s FROM %s WHERE actor_ref = ? AND scope_kind = ? AND scope_id = ? AND document_hash = ? AND plan_hash = ? AND prune = ? AND status = ? AND expires_at > ? AND document IS NOT NULL ORDER BY id DESC',
            self::COLUMNS,
            $this->tables->run(),
        );
        $row = $this->connection->fetchAssociative(
            $this->connection->getDatabasePlatform()->modifyLimitQuery($sql, 1),
            [$actor->value, $scope->kind->value, $scope->storedId(), $document->value, $plan->value, $prune, RunStatus::Planned->value, $this->format($now)],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::STRING, ParameterType::STRING],
        );

        return is_array($row) ? $this->record($row) : null;
    }

    /**
     * {@inheritDoc}
     */
    public function transition(RunId $run, ?int $version, array $from, RunStatus $to, ?string $errorCode = null, ?DateTimeImmutable $expiresAt = null, ?DateTimeImmutable $staleBefore = null): bool
    {
        if ($from === []) {
            return false;
        }
        $now    = $this->format($this->clock->now());
        $set    = ['status = ?', 'version = version + 1', 'updated_at = ?'];
        $params = [$to->value, $now];
        $types  = [ParameterType::STRING, ParameterType::STRING];
        if ($errorCode !== null) {
            $set[]    = 'error_code = ?';
            $params[] = $errorCode;
            $types[]  = ParameterType::STRING;
        }
        if ($expiresAt instanceof DateTimeImmutable) {
            $set[]    = 'expires_at = ?';
            $params[] = $this->format($expiresAt);
            $types[]  = ParameterType::STRING;
        }
        if ($to->isFinal()) {
            $set[]    = 'document = NULL';
            $set[]    = 'finished_at = ?';
            $params[] = $now;
            $types[]  = ParameterType::STRING;
        }
        $statuses = [];
        foreach ($from as $status) {
            $statuses[] = $status->value;
        }
        $where    = 'id = ? AND status IN (?)';
        $params[] = $run->value;
        $params[] = $statuses;
        $types[]  = ParameterType::STRING;
        $types[]  = ArrayParameterType::STRING;
        if ($staleBefore instanceof DateTimeImmutable) {
            // A claim: a `running` run is taken over only after its lease (crashed worker).
            $where .= ' AND (status <> ? OR updated_at < ?)';
            $params[] = RunStatus::Running->value;
            $params[] = $this->format($staleBefore);
            $types[]  = ParameterType::STRING;
            $types[]  = ParameterType::STRING;
        }
        if ($version !== null) {
            $where .= ' AND version = ?';
            $params[] = $version;
            $types[]  = ParameterType::INTEGER;
        }

        return $this->connection->executeStatement(sprintf('UPDATE %s SET %s WHERE %s', $this->tables->run(), implode(', ', $set), $where), $params, $types) === 1;
    }

    /**
     * {@inheritDoc}
     */
    public function expire(DateTimeImmutable $now, int $batchSize): int
    {
        $at      = $this->format($now);
        $changed = 0;
        foreach ([[RunStatus::Planned, RunStatus::Queued], [RunStatus::PartiallyApplied]] as $statuses) {
            $values = [];
            foreach ($statuses as $status) {
                $values[] = $status->value;
            }
            do {
                $ids = $this->connection->fetchFirstColumn(
                    $this->connection->getDatabasePlatform()->modifyLimitQuery(sprintf('SELECT id FROM %s WHERE status IN (?) AND expires_at < ? AND document IS NOT NULL ORDER BY id', $this->tables->run()), $batchSize),
                    [$values, $at],
                    [ArrayParameterType::STRING, ParameterType::STRING],
                );
                if ($ids !== []) {
                    $sql = $statuses === [RunStatus::PartiallyApplied]
                        ? sprintf('UPDATE %s SET document = NULL, updated_at = ?, version = version + 1 WHERE id IN (?) AND status IN (?)', $this->tables->run())
                        : sprintf("UPDATE %s SET document = NULL, updated_at = ?, version = version + 1, status = 'expired', finished_at = ? WHERE id IN (?) AND status IN (?)", $this->tables->run());
                    $params = $statuses === [RunStatus::PartiallyApplied] ? [$at, $ids, $values] : [$at, $at, $ids, $values];
                    $types  = $statuses === [RunStatus::PartiallyApplied]
                        ? [ParameterType::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING]
                        : [ParameterType::STRING, ParameterType::STRING, ArrayParameterType::STRING, ArrayParameterType::STRING];
                    $changed += (int) $this->connection->executeStatement($sql, $params, $types);
                }
            } while (count($ids) === $batchSize);
        }

        return $changed;
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
            sprintf('UPDATE %s SET status = ?, error_code = ?, finished_at = ?, updated_at = ?%s, version = version + 1 WHERE id = ?', $this->tables->run(), $status->isFinal() ? ', document = NULL' : ''),
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
    public function list(?SeedScope $scope, ?RunStatus $status, int $limit, ?RunId $before = null, ?ActorRef $actor = null): array
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
        if ($actor instanceof ActorRef) {
            $where[]  = 'actor_ref = ?';
            $params[] = $actor->value;
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
        $scopeId  = is_string($row['scope_id']) && $row['scope_id'] !== '' ? new ScopeId($row['scope_id']) : null;
        $manifest = BatchManifest::fromStored(is_string($row['batch_digests']) ? json_decode($row['batch_digests'], true) : null);

        return new RunRecord(
            new RunId($this->text($row['id'])),
            new SeedScope(new ScopeKind($this->text($row['scope_kind'])), $scopeId),
            is_string($row['actor_ref']) ? new ActorRef($row['actor_ref']) : null,
            Channel::from($this->text($row['channel'])),
            RunStatus::from($this->text($row['status'])),
            $this->intMap($row['providers']),
            (bool) $row['prune'],
            is_string($row['document_hash']) ? new DocumentHash($row['document_hash']) : null,
            new PlanHash($this->text($row['plan_hash'])),
            $manifest instanceof BatchManifest ? $manifest->flat() : $this->stringList($row['batch_digests']),
            is_numeric($row['batches_done']) ? (int) $row['batches_done'] : 0,
            $this->intMap($row['counts']),
            is_string($row['error_code']) ? $row['error_code'] : null,
            new DateTimeImmutable($this->text($row['created_at']), new DateTimeZone('UTC')),
            is_string($row['finished_at']) ? new DateTimeImmutable($row['finished_at'], new DateTimeZone('UTC')) : null,
            $manifest,
            is_numeric($row['version']) ? (int) $row['version'] : 1,
            is_string($row['expires_at']) ? new DateTimeImmutable($row['expires_at'], new DateTimeZone('UTC')) : null,
            is_numeric($row['has_document']) && (int) $row['has_document'] === 1,
            is_string($row['updated_at']) ? new DateTimeImmutable($row['updated_at'], new DateTimeZone('UTC')) : null,
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
    private function text(mixed $value): string
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
