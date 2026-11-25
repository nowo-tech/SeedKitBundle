<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Application;

use Closure;
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
use Nowo\SeedKitBundle\Domain\Plan\RowChange;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Tests\Fixtures\Provider\InMemoryStore;

use function count;

use const SORT_STRING;

/**
 * A provider whose behaviour a test scripts: rows in memory by canonical key (key property `code`), optional hooks
 * replacing planBatch / planDeletions / applyBatch / exportCurrent.
 */
final class ScriptedProvider extends AbstractSeedProvider
{
    /** @var array<string, array<string, mixed>> canonical key → row */
    public array $rows = [];

    /** @var list<string> */
    public array $log = [];

    /** @var (Closure(SeedContext, list<SeedItem>, PlanOptions): BatchPlan)|null */
    public ?Closure $onPlanBatch = null;

    /** @var (Closure(SeedContext, NaturalKeySet, PlanOptions): iterable<RowChange>)|null */
    public ?Closure $onPlanDeletions = null;

    /** @var (Closure(SeedContext, AppliedBatch): BatchResult)|null */
    public ?Closure $onApplyBatch = null;

    /** @var (Closure(SeedContext): iterable<mixed>)|null */
    public ?Closure $onExportCurrent = null;

    /**
     * @param array<string, mixed>|null $schema item schema (default: code + label + note)
     * @param list<string> $kinds
     * @param list<string> $dependsOn
     */
    public function __construct(
        private readonly string $name = 'demo.items',
        private readonly ?array $schema = null,
        private readonly array $kinds = ['platform', 'organization'],
        private readonly array $dependsOn = [],
        private readonly int $version = 1,
    ) {
    }

    /**
     * Stores a row under its code.
     *
     * @param array<string, mixed> $row
     */
    public function seed(string $code, array $row = []): void
    {
        $this->rows[(new NaturalKey([$code]))->canonical] = ['code' => $code] + $row;
    }

    public function name(): ProviderName
    {
        return new ProviderName($this->name);
    }

    public function version(): int
    {
        return $this->version;
    }

    public function scopes(): array
    {
        return array_map(static fn (string $kind): ScopeKind => new ScopeKind($kind), $this->kinds);
    }

    public function dependsOn(): array
    {
        return array_map(static fn (string $name): ProviderName => new ProviderName($name), $this->dependsOn);
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema($this->schema ?? [
            'type'                 => 'object',
            'required'             => ['code'],
            'additionalProperties' => false,
            'x-seed-key'           => ['code'],
            'properties'           => [
                'code'  => ['type' => 'string', 'maxLength' => 32, 'description' => 'Code'],
                'label' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Label'],
                'note'  => ['type' => 'string', 'maxLength' => 64, 'description' => 'Note'],
            ],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        if ($this->onExportCurrent instanceof Closure) {
            // a scripted provider may break the contract on purpose (non-item rows)
            yield from ($this->onExportCurrent)($context); // @phpstan-ignore generator.valueType

            return;
        }
        $rows = $this->rows;
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            yield new SeedItem(0, new NaturalKey([InMemoryStore::text($row['code'])]), $row);
        }
    }

    public function planBatch(SeedContext $context, array $items, PlanOptions $options): BatchPlan
    {
        $this->log[] = 'plan:' . count($items) . ($options->lockForUpdate ? ':lock' : '');

        return $this->onPlanBatch instanceof Closure ? ($this->onPlanBatch)($context, $items, $options) : parent::planBatch($context, $items, $options);
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        if ($this->onPlanDeletions instanceof Closure) {
            yield from ($this->onPlanDeletions)($context, $seen, $options);

            return;
        }
        $rows = $this->rows;
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            $key = new NaturalKey([InMemoryStore::text($row['code'])]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($row));
            }
        }
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $this->log[] = 'apply:' . $batch->number . ':' . implode(',', array_map(static fn (RowChange $c): string => $c->op->letter() . $c->key->canonical, $batch->changes));
        if ($this->onApplyBatch instanceof Closure) {
            return ($this->onApplyBatch)($context, $batch);
        }
        $created = $updated = $deleted = 0;
        foreach ($batch->changes as $change) {
            if ($change->op === ChangeOp::Delete) {
                unset($this->rows[$change->key->canonical]);
                ++$deleted;

                continue;
            }
            $item                                = $batch->item($change);
            $this->rows[$change->key->canonical] = $item instanceof SeedItem ? $item->data : [];
            $change->op === ChangeOp::Create ? ++$created : ++$updated;
        }

        return new BatchResult($created, $updated, $deleted);
    }

    public function afterBatch(SeedContext $context): void
    {
        $this->log[] = 'after';
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        $rows = [];
        foreach ($keys as $key) {
            if (isset($this->rows[$key->canonical])) {
                $rows[$key->canonical] = $this->rows[$key->canonical];
            }
        }

        return $rows;
    }
}
