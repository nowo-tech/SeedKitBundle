<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Host;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

use function array_map;
use function strcmp;
use function usort;

/**
 * DB-backed fixture provider `host.articles` of a standard host (registered by an explicit tag with `name` and
 * `depends_on: [host.tags]`): rows in `host_article` per scope, key `code`, a `tag` reference, an integer `level`,
 * a personal-data `owner`. Row-level visibility: the actor `bob` reads (and plans against) only the rows he owns.
 */
final class HostArticleProvider extends AbstractSeedProvider
{
    /** The actor with restricted visibility. */
    public const RESTRICTED = 'bob';

    public function __construct(private readonly Connection $connection, private readonly HostHooks $hooks)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('host.articles');
    }

    public function version(): int
    {
        return 1;
    }

    public function scopes(): array
    {
        return [new ScopeKind('platform'), new ScopeKind('organization')];
    }

    public function dependsOn(): array
    {
        return [new ProviderName('host.tags')];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Host articles',
            'type'                 => 'object',
            'required'             => ['code', 'title'],
            'additionalProperties' => false,
            'x-seed-key'           => ['code'],
            'x-seed-ref'           => ['tag' => 'host.tags'],
            'properties'           => [
                'code'  => ['type' => 'string', 'maxLength' => 32, 'description' => 'Code'],
                'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Title'],
                'tag'   => ['type' => 'string', 'maxLength' => 64, 'description' => 'Tag slug'],
                'level' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9, 'description' => 'Access level'],
                'owner' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Owner', 'x-seed-personal-data' => true],
            ],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->rows($context, false) as $row) {
            yield new SeedItem(0, new NaturalKey([HostSql::text($row['code'])]), $row);
        }
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        foreach ($this->rows($context, $options->lockForUpdate) as $row) {
            $key = new NaturalKey([HostSql::text($row['code'])]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($row));
            }
        }
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $this->hooks->fire('host.articles', $batch);
        $created = $updated = $deleted = 0;
        $scope   = $context->scope->key();
        foreach ($batch->changes as $change) {
            $code = HostSql::text($change->key->components[0]);
            if ($change->op === ChangeOp::Delete) {
                $deleted += (int) $this->connection->executeStatement('DELETE FROM host_article WHERE scope_key = ? AND code = ?', [$scope, $code]);

                continue;
            }
            $data   = $batch->item($change)->data ?? [];
            $values = [HostSql::text($data['title'] ?? ''), HostSql::nullableText($data['tag'] ?? null), HostSql::nullableInt($data['level'] ?? null), HostSql::nullableText($data['owner'] ?? null), $scope, $code];
            $types  = [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING];
            if ($change->op === ChangeOp::Create) {
                $this->connection->executeStatement('INSERT INTO host_article (title, tag, level, owner, scope_key, code) VALUES (?, ?, ?, ?, ?, ?)', $values, $types);
                ++$created;
            } else {
                $this->connection->executeStatement('UPDATE host_article SET title = ?, tag = ?, level = ?, owner = ? WHERE scope_key = ? AND code = ?', $values, $types);
                ++$updated;
            }
        }

        return new BatchResult($created, $updated, $deleted);
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        if ($keys === []) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, title, tag, level, owner FROM host_article WHERE scope_key = ? AND code IN (?) ORDER BY code' . HostSql::lock($this->connection, $options->lockForUpdate),
            [$context->scope->key(), array_map(static fn (NaturalKey $key): string => HostSql::text($key->components[0]), $keys)],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
        $current = [];
        foreach ($rows as $row) {
            if (self::visible($context, $row)) {
                $current[(new NaturalKey([HostSql::text($row['code'])]))->canonical] = self::row($row);
            }
        }

        return $current;
    }

    /**
     * The visible rows of the scope in natural-key byte order.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(SeedContext $context, bool $lock): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative('SELECT code, title, tag, level, owner FROM host_article WHERE scope_key = ?' . HostSql::lock($this->connection, $lock), [$context->scope->key()]) as $row) {
            if (self::visible($context, $row)) {
                $rows[] = self::row($row);
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp(HostSql::text($a['code']), HostSql::text($b['code'])));

        return $rows;
    }

    /**
     * Whether the actor may read the row.
     *
     * @param array<string, mixed> $row
     */
    private static function visible(SeedContext $context, array $row): bool
    {
        return $context->actor->value !== self::RESTRICTED || $row['owner'] === self::RESTRICTED;
    }

    /**
     * A row as item values (null columns absent).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $data = ['code' => HostSql::text($row['code']), 'title' => HostSql::text($row['title'])];
        if ($row['tag'] !== null) {
            $data['tag'] = HostSql::text($row['tag']);
        }
        if ($row['level'] !== null) {
            $data['level'] = HostSql::nullableInt($row['level']);
        }
        if ($row['owner'] !== null) {
            $data['owner'] = HostSql::text($row['owner']);
        }

        return $data;
    }
}
