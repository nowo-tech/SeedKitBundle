<?php

declare(strict_types=1);

namespace App\Seed;

use App\Catalog\CatalogService;
use App\Entity\Product;
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
use Nowo\SeedKitBundle\Domain\Plan\RowError;
use Nowo\SeedKitBundle\Domain\Provider\AbstractSeedProvider;
use Nowo\SeedKitBundle\Domain\Schema\SeedSchema;
use Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute\AsSeedProvider;

use function array_map;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * Seed provider `catalog.products` (platform scope), applied after `catalog.categories`: key `sku`, `name`, `category`
 * (the slug of a category: `x-seed-ref`), `price` (a float: `x-seed-float`) and `active`. Stateless (worker-safe): it
 * reads and writes only through {@see CatalogService}.
 */
#[AsSeedProvider(name: 'catalog.products', dependsOn: ['catalog.categories'])]
final class ProductProvider extends AbstractSeedProvider
{
    /**
     * Builds the provider over the catalog service.
     */
    public function __construct(private readonly CatalogService $catalog)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function name(): ProviderName
    {
        return new ProviderName('catalog.products');
    }

    /**
     * {@inheritDoc}
     */
    public function version(): int
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function scopes(): array
    {
        return [new ScopeKind(ScopeKind::PLATFORM)];
    }

    /**
     * {@inheritDoc}
     */
    public function dependsOn(): array
    {
        return [new ProviderName('catalog.categories')];
    }

    /**
     * {@inheritDoc}
     */
    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Catalog products',
            'type'                 => 'object',
            'required'             => ['sku', 'name', 'category', 'price'],
            'additionalProperties' => false,
            'x-seed-key'           => ['sku'],
            'x-seed-ref'           => ['category' => 'catalog.categories'],
            'properties'           => [
                'sku'      => ['type' => 'string', 'maxLength' => 64, 'description' => 'Stock keeping unit'],
                'name'     => ['type' => 'string', 'maxLength' => 200, 'description' => 'Name'],
                'category' => ['type' => 'string', 'maxLength' => 64, 'description' => 'Category slug'],
                'price'    => ['type' => 'number', 'x-seed-float' => true, 'minimum' => 0, 'description' => 'Unit price'],
                'active'   => ['type' => 'boolean', 'description' => 'Sold in the shop'],
            ],
            'examples' => [['sku' => 'BK-0001', 'name' => 'Symfony in practice', 'category' => 'books', 'price' => 29.9, 'active' => true]],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->catalog->allProducts() as $product) {
            yield new SeedItem(0, new NaturalKey([$product->getSku()]), $this->row($product));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        foreach ($this->catalog->allProducts() as $product) {
            $key = new NaturalKey([$product->getSku()]);
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($this->row($product)));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $skus      = array_map(static fn (RowChange $change): string => (string) $change->key->components[0], $batch->changes);
        $known     = $this->catalog->products($skus, true);
        $slugs     = [];
        foreach ($batch->items as $item) {
            if (is_string($item->data['category'] ?? null)) {
                $slugs[] = $item->data['category'];
            }
        }
        $categories = $this->catalog->categories($slugs);
        $created = $updated = $deleted = 0;
        foreach ($batch->changes as $change) {
            $sku = (string) $change->key->components[0];
            if ($change->op === ChangeOp::Delete) {
                if (isset($known[$sku])) {
                    $this->catalog->removeProduct($known[$sku]);
                    ++$deleted;
                }

                continue;
            }
            $item     = $batch->item($change);
            $data     = $item !== null ? $item->data : [];
            $existing = $known[$sku] ?? null;
            $category = $categories[is_string($data['category'] ?? null) ? $data['category'] : ''] ?? $existing?->getCategory();
            if ($category === null) {
                continue;
            }
            $price  = $data['price'] ?? 0;
            $active = $data['active'] ?? $existing?->isActive() ?? true;
            $this->catalog->saveProduct($sku, is_string($data['name'] ?? null) ? $data['name'] : $sku, $category, is_float($price) || is_int($price) ? $price : 0, is_bool($active) ? $active : true, $existing);
            $change->op === ChangeOp::Create ? ++$created : ++$updated;
        }
        $this->catalog->flush();

        return new BatchResult($created, $updated, $deleted);
    }

    /**
     * {@inheritDoc}
     */
    public function afterBatch(SeedContext $context): void
    {
        $this->catalog->clear();
    }

    /**
     * {@inheritDoc}
     */
    protected function currentRows(SeedContext $context, array $keys, PlanOptions $options): array
    {
        $found = $this->catalog->products(array_map(static fn (NaturalKey $key): string => (string) $key->components[0], $keys), $options->lockForUpdate);
        $rows  = [];
        foreach ($keys as $key) {
            $product = $found[(string) $key->components[0]] ?? null;
            if ($product !== null) {
                $rows[$key->canonical] = $this->row($product);
            }
        }

        return $rows;
    }

    /**
     * {@inheritDoc}
     */
    protected function validateItem(SeedContext $context, SeedItem $item): array
    {
        $slug = $item->data['category'] ?? null;
        if (!is_string($slug)) {
            return [];
        }
        $key = new NaturalKey([$slug]);
        if ($context->pending->has(new ProviderName('catalog.categories'), $key) || $this->catalog->categories([$slug]) !== []) {
            return [];
        }

        return [new RowError($item->index, $item->key, '/category', 'reference', 'seed_kit.row.reference')];
    }

    /**
     * The current values of a product row.
     *
     * @return array<string, bool|float|string>
     */
    private function row(Product $product): array
    {
        return [
            'sku'      => $product->getSku(),
            'name'     => $product->getName(),
            'category' => $product->getCategory()->getSlug(),
            'price'    => (float) $product->getPrice(),
            'active'   => $product->isActive(),
        ];
    }
}
