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
use Nowo\SeedKitBundle\Domain\Model\SecretReference;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute\AsSeedProvider;
use RuntimeException;

use function array_map;
use function sprintf;
use function strcmp;
use function usort;

/**
 * DB-backed fixture provider `host.tags` of a standard host (registered by `#[AsSeedProvider]` autoconfiguration):
 * rows in the host table `host_tag` per scope, key `slug` (lower-cased), a secret `apiToken` (stored as a vault
 * reference), an optional `color` that an absent value clears, a read-only `createdAt`. The label `boom` makes
 * applyBatch() fail (provider failure tests).
 */
#[AsSeedProvider(name: 'host.tags')]
final class HostTagProvider extends AbstractSeedProvider
{
    /** The label that makes applyBatch() throw. */
    public const BOOM = 'boom';

    public function __construct(private readonly Connection $connection, private readonly HostHooks $hooks)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('host.tags');
    }

    public function version(): int
    {
        return 1;
    }

    public function scopes(): array
    {
        return [new ScopeKind('platform'), new ScopeKind('organization')];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Host tags',
            'type'                 => 'object',
            'required'             => ['slug', 'label'],
            'additionalProperties' => false,
            'x-seed-key'           => ['slug'],
            'x-seed-key-normalise' => ['slug' => 'lower'],
            'properties'           => [
                'slug'      => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9-]+$', 'description' => 'Tag slug'],
                'label'     => ['type' => 'string', 'maxLength' => 120, 'description' => 'Label'],
                'color'     => ['type' => 'string', 'enum' => ['red', 'green', 'blue'], 'description' => 'Color', 'x-seed-omit-means' => 'clear'],
                'apiToken'  => ['type' => 'string', 'maxLength' => 210, 'description' => 'Token reference', 'x-seed-secret' => true],
                'createdAt' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Creation', 'x-seed-readonly' => true],
            ],
            'examples' => [['slug' => 'news', 'label' => 'News']],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->rows($context) as $row) {
            if (isset($row['apiToken'])) {
                $row['apiToken'] = new SecretReference(HostSql::text($row['apiToken']));
            }
            yield new SeedItem(0, new NaturalKey([HostSql::text($row['slug'])]), $row);
        }
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        foreach ($this->rows($context, $options->lockForUpdate) as $row) {
            $key = new NaturalKey([HostSql::text($row['slug'])]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($row));
            }
        }
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $this->hooks->fire('host.tags', $batch);
        $created = $updated = $deleted = 0;
        $scope   = $context->scope->key();
        foreach ($batch->changes as $change) {
            $slug = HostSql::text($change->key->components[0]);
            if ($change->op === ChangeOp::Delete) {
                $deleted += (int) $this->connection->executeStatement('DELETE FROM host_tag WHERE scope_key = ? AND slug = ?', [$scope, $slug]);

                continue;
            }
            $data = $batch->item($change)->data ?? [];
            if (($data['label'] ?? null) === self::BOOM) {
                throw new RuntimeException('The host refused the tag.');
            }
            $values = [HostSql::text($data['label'] ?? ''), HostSql::nullableText($data['color'] ?? null), HostSql::nullableText($data['apiToken'] ?? null)];
            if ($change->op === ChangeOp::Create) {
                $this->connection->executeStatement('INSERT INTO host_tag (label, color, api_token, scope_key, slug, created_at) VALUES (?, ?, ?, ?, ?, ?)', [...$values, $scope, $slug, '2026-01-01']);
                ++$created;
            } else {
                $this->connection->executeStatement('UPDATE host_tag SET label = ?, color = ?, api_token = ? WHERE scope_key = ? AND slug = ?', [...$values, $scope, $slug]);
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
            'SELECT slug, label, color, api_token, created_at FROM host_tag WHERE scope_key = ? AND slug IN (?) ORDER BY slug' . HostSql::lock($this->connection, $options->lockForUpdate),
            [$context->scope->key(), array_map(static fn (NaturalKey $key): string => HostSql::text($key->components[0]), $keys)],
            [ParameterType::STRING, ArrayParameterType::STRING],
        );
        $current = [];
        foreach ($rows as $row) {
            $current[(new NaturalKey([HostSql::text($row['slug'])]))->canonical] = self::row($row);
        }

        return $current;
    }

    /**
     * The rows of the scope in natural-key byte order.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(SeedContext $context, bool $lock = false): array
    {
        $rows = [];
        foreach ($this->connection->fetchAllAssociative(sprintf('SELECT slug, label, color, api_token, created_at FROM host_tag WHERE scope_key = ?%s', HostSql::lock($this->connection, $lock)), [$context->scope->key()]) as $row) {
            $rows[] = self::row($row);
        }
        usort($rows, static fn (array $a, array $b): int => strcmp(HostSql::text($a['slug']), HostSql::text($b['slug'])));

        return $rows;
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
        $data = ['slug' => HostSql::text($row['slug']), 'label' => HostSql::text($row['label'])];
        if ($row['color'] !== null) {
            $data['color'] = HostSql::text($row['color']);
        }
        if ($row['api_token'] !== null) {
            $data['apiToken'] = HostSql::text($row['api_token']);
        }
        $data['createdAt'] = HostSql::text($row['created_at']);

        return $data;
    }
}
