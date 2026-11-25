<?php

declare(strict_types=1);

namespace Nowo\SeedKitBundle\Tests\Fixtures\Provider;

use Nowo\SeedKitBundle\Domain\Context\SeedContext;
use Nowo\SeedKitBundle\Domain\Document\SeedItem;
use Nowo\SeedKitBundle\Domain\Model\NaturalKey;
use Nowo\SeedKitBundle\Domain\Model\ProviderName;
use Nowo\SeedKitBundle\Domain\Model\ScopeKind;
use Nowo\SeedKitBundle\Domain\Plan\AppliedBatch;
use Nowo\SeedKitBundle\Domain\Plan\BatchResult;
use Nowo\SeedKitBundle\Domain\Plan\ChangeOp;
use Nowo\SeedKitBundle\Domain\Plan\PlanOptions;
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;

use function is_string;
use function ksort;

use const SORT_STRING;

/**
 * Fixture provider `demo.articles` (organization), depends on `demo.tags`: key `code`; `tag` references a tag;
 * `price` is a float; `level` may not exceed the actor's own level (no escalation, QB-SK-3: actor `bob` holds level 1,
 * others 9); rows are owned and actor `bob` only sees his own (row-level visibility).
 */
final class ArticleProvider extends AbstractSeedProvider
{
    /**
     * Builds the provider over the store.
     */
    public function __construct(public readonly InMemoryStore $store)
    {
    }

    public function name(): ProviderName
    {
        return new ProviderName('demo.articles');
    }

    public function version(): int
    {
        return 1;
    }

    public function scopes(): array
    {
        return [new ScopeKind('organization')];
    }

    public function dependsOn(): array
    {
        return [new ProviderName('demo.tags')];
    }

    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'type'                 => 'object',
            'required'             => ['code', 'title'],
            'additionalProperties' => false,
            'x-seed-key'           => ['code'],
            'x-seed-ref'           => ['tag' => 'demo.tags'],
            'properties'           => [
                'code'  => ['type' => 'string', 'maxLength' => 32, 'description' => 'Code'],
                'title' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Title'],
                'tag'   => ['type' => 'string', 'maxLength' => 64, 'description' => 'Tag slug'],
                'price' => ['type' => 'number', 'x-seed-float' => true, 'description' => 'Price'],
                'level' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9, 'description' => 'Access level'],
                'owner' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Owner', 'x-seed-personal-data' => true],
            ],
        ]);
    }

    public function exportCurrent(SeedContext $context): iterable
    {
        $rows = $this->store->rows['demo.articles'] ?? [];
        ksort($rows, SORT_STRING);
        foreach ($rows as $row) {
            if ($context->actor->value === 'bob' && ($row['owner'] ?? null) !== 'bob') {
                continue;
            }
            yield new SeedItem(0, new NaturalKey([InMemoryStore::text($row['code'])]), $row);
        }
    }

    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        ++$this->store->applyCalls;
        $created = $updated = 0;
        foreach ($batch->changes as $change) {
            $item = $batch->item($change);
            $row  = $this->store->rows['demo.articles'][$change->key->canonical] ?? [];
            foreach ($item instanceof SeedItem ? $item->data : [] as $property => $value) {
                $row[$property] = $value;
            }
            $this->store->rows['demo.articles'][$change->key->canonical] = $row;
            $change->op === ChangeOp::Create ? ++$created : ++$updated;
        }

        return new BatchResult($created, $updated);
    }

    protected function validateItem(SeedContext $context, SeedItem $item): array
    {
        $errors = [];
        $level  = $item->data['level'] ?? 0;
        if ($level > ($context->actor->value === 'bob' ? 1 : 9)) {
            $errors[] = new RowError($item->index, $item->key, '/level', 'escalation', 'seed_kit.row.escalation');
        }
        $tag = $item->data['tag'] ?? null;
        if (is_string($tag) && !isset($this->store->rows['demo.tags']['["' . $tag . '"]']) && !$context->pending->has(new ProviderName('demo.tags'), new NaturalKey([$tag]))) {
            $errors[] = new RowError($item->index, $item->key, '/tag', 'reference', 'seed_kit.row.reference');
        }

        return $errors;
    }

    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        $rows = [];
        foreach ($keys as $key) {
            if (isset($this->store->rows['demo.articles'][$key->canonical])) {
                $rows[$key->canonical] = $this->store->rows['demo.articles'][$key->canonical];
            }
        }

        return $rows;
    }
}
