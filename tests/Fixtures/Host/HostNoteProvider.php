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
use Nowo\SeedKitBundle\Domain\Plan\BatchPlan;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\NaturalKeySet;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Port\SeedProviderInterface;
use Nowo\SeedKitBundle\Domain\Provider\RowDiffer;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

use function array_map;

/**
 * DB-backed fixture provider `host.notes` of a DDD host: implements {@see SeedProviderInterface} directly (no base
 * class), plans with {@see RowDiffer}, writes `host_note` on the seed connection. Registered by an explicit tag. The
 * property `body` has no description (a lint warning, not an error).
 */
final readonly class HostNoteProvider implements SeedProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('host.notes');
    }

    public function version(): int
    {
        return 2;
    }

    public function scopes(): array
    {
        return [new ScopeKind('platform'), new ScopeKind('organization')];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'type'                 => 'object',
            'required'             => ['code', 'body'],
            'additionalProperties' => false,
            'x-seed-key'           => ['code'],
            'properties'           => [
                'code' => ['type' => 'string', 'maxLength' => 32, 'description' => 'Code'],
                'body' => ['type' => 'string', 'maxLength' => 500],
            ],
        ]);
    }

    public function dependsOn(): array
    {
        return [new ProviderName('host.tags')];
    }

    public function exportEmpty(SeedContext $context): iterable
    {
        return [['code' => 'welcome', 'body' => 'Hello']];
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->connection->fetchAllAssociative('SELECT code, body FROM host_note WHERE scope_key = ? ORDER BY code', [$context->scope->key()]) as $row) {
            yield new SeedItem(0, new NaturalKey([HostSql::text($row['code'])]), ['code' => HostSql::text($row['code']), 'body' => HostSql::text($row['body'])]);
        }
    }

    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan
    {
        $current = [];
        if ($items !== []) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT code, body FROM host_note WHERE scope_key = ? AND code IN (?)' . HostSql::lock($this->connection, $options->lockForUpdate),
                [$context->scope->key(), array_map(static fn (SeedItem $item): string => HostSql::text($item->key->components[0]), $items)],
                [ParameterType::STRING, ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $current[(new NaturalKey([HostSql::text($row['code'])]))->canonical] = ['code' => HostSql::text($row['code']), 'body' => HostSql::text($row['body'])];
            }
        }
        $schema  = $this->schema();
        $changes = [];
        foreach ($items as $item) {
            $changes[] = RowDiffer::diff($schema, $item, $current[$item->key->canonical] ?? null);
        }

        return new BatchPlan($changes);
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        return [];
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $created = $updated = 0;
        foreach ($batch->changes as $change) {
            $values = [HostSql::text($batch->item($change)?->data['body'] ?? ''), $context->scope->key(), HostSql::text($change->key->components[0])];
            if ($change->op === ChangeOp::Create) {
                $this->connection->executeStatement('INSERT INTO host_note (body, scope_key, code) VALUES (?, ?, ?)', $values);
                ++$created;
            } else {
                $this->connection->executeStatement('UPDATE host_note SET body = ? WHERE scope_key = ? AND code = ?', $values);
                ++$updated;
            }
        }

        return new BatchResult($created, $updated);
    }

    public function afterBatch(SeedContext $context): void
    {
    }
}
