<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

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

use function ksort;
use function strcmp;

use const SORT_STRING;

/**
 * Fixture provider `demo.tags` (platform and organization): key `slug` (lower-cased), a secret `apiToken`, a read-only
 * `createdAt`, an optional `color` that an absent value clears.
 */
final class TagProvider extends AbstractSeedProvider
{
    /**
     * Builds the provider over the store.
     */
    public function __construct(public readonly InMemoryStore $store)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('demo.tags');
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
            'title'                => 'Tags',
            'type'                 => 'object',
            'required'             => ['slug', 'label'],
            'additionalProperties' => false,
            'x-seed-key'           => ['slug'],
            'x-seed-key-normalise' => ['slug' => 'lower'],
            'properties'           => [
                'slug'      => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9-]+$', 'description' => 'Tag slug'],
                'label'     => ['type' => 'string', 'maxLength' => 120, 'description' => 'Label', 'x-seed-personal-data' => false],
                'color'     => ['type' => 'string', 'enum' => ['red', 'green', 'blue'], 'description' => 'Color', 'x-seed-omit-means' => 'clear'],
                'apiToken'  => ['type' => 'string', 'maxLength' => 210, 'description' => 'Token reference', 'x-seed-secret' => true],
                'createdAt' => ['type' => 'string', 'maxLength' => 40, 'description' => 'Creation', 'x-seed-readonly' => true],
            ],
            'examples' => [['slug' => 'news', 'label' => 'News']],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        $rows = $this->store->rows['demo.tags'] ?? [];
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            $data = $row;
            if (isset($data['apiToken'])) {
                $data['apiToken'] = new SecretReference(InMemoryStore::text($data['apiToken']));
            }
            yield new SeedItem(0, new NaturalKey([InMemoryStore::text($row['slug'])]), $data);
        }
    }

    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        $rows = $this->store->rows['demo.tags'] ?? [];
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            $key = new NaturalKey([InMemoryStore::text($row['slug'])]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($row));
            }
        }
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        ++$this->store->applyCalls;
        $created = $updated = $deleted = 0;
        foreach ($batch->changes as $change) {
            $key = $change->key->canonical;
            if ($change->op === ChangeOp::Delete) {
                unset($this->store->rows['demo.tags'][$key]);
                ++$deleted;

                continue;
            }
            $item = $batch->item($change);
            $row  = $this->store->rows['demo.tags'][$key] ?? ['createdAt' => '2026-01-01'];
            foreach ($item !== null ? $item->data : [] as $property => $value) {
                $row[$property] = $value;
            }
            if ($item !== null && !$item->has('color')) {
                unset($row['color']);
            }
            $this->store->rows['demo.tags'][$key] = $row;
            $change->op === ChangeOp::Create ? ++$created : ++$updated;
        }

        return new BatchResult($created, $updated, $deleted);
    }

    public function afterBatch(SeedContext $context): void
    {
        ++$this->store->afterBatchCalls;
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        if ($options->lockForUpdate) {
            ++$this->store->lockedPlans;
        }
        $rows = [];
        foreach ($keys as $key) {
            if (isset($this->store->rows['demo.tags'][$key->canonical])) {
                $rows[$key->canonical] = $this->store->rows['demo.tags'][$key->canonical];
            }
        }

        return $rows;
    }

    /**
     * Canonical order helper for tests.
     */
    public static function compare(string $a, string $b): int
    {
        return strcmp($a, $b);
    }
}
