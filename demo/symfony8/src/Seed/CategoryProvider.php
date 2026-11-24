<?php

declare(strict_types=1);

namespace App\Seed;

use App\Catalog\CatalogService;
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
use Nowo\SeedKitBundle\Infrastructure\Symfony\Attribute\AsSeedProvider;

use function array_map;
use function is_string;

/**
 * Seed provider `catalog.categories` (platform scope): key `slug` (lower-cased), `label`, optional `description`.
 * Stateless (worker-safe): it reads and writes only through {@see CatalogService}.
 */
#[AsSeedProvider(name: 'catalog.categories')]
final class CategoryProvider extends AbstractSeedProvider
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
        return new ProviderName('catalog.categories');
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
    public function schema(): SeedSchema
    {
        return new SeedSchema([
            'title'                => 'Catalog categories',
            'type'                 => 'object',
            'required'             => ['slug', 'label'],
            'additionalProperties' => false,
            'x-seed-key'           => ['slug'],
            'x-seed-key-normalise' => ['slug' => 'lower'],
            'properties'           => [
                'slug'        => ['type' => 'string', 'maxLength' => 64, 'pattern' => '^[a-z0-9-]+$', 'description' => 'Category slug'],
                'label'       => ['type' => 'string', 'maxLength' => 120, 'description' => 'Label'],
                'description' => ['type' => 'string', 'maxLength' => 500, 'description' => 'Description', 'x-seed-omit-means' => 'clear'],
            ],
            'examples' => [['slug' => 'books', 'label' => 'Books', 'description' => 'Printed and digital books']],
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function exportCurrent(SeedContext $context): iterable
    {
        foreach ($this->catalog->allCategories() as $category) {
            $row = ['slug' => $category->getSlug(), 'label' => $category->getLabel()];
            if ($category->getDescription() !== null) {
                $row['description'] = $category->getDescription();
            }

            yield new SeedItem(0, new NaturalKey([$category->getSlug()]), $row);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function planDeletions(SeedContext $context, NaturalKeySet $seen, PlanOptions $options): iterable
    {
        foreach ($this->catalog->allCategories() as $category) {
            $key = new NaturalKey([$category->getSlug()]);
            // A category still used by products can only go in the same run as its products (prune both documents).
            if (!$seen->has($key)) {
                yield RowChange::delete($key, $this->fingerprint($this->row($category->getSlug(), $category->getLabel(), $category->getDescription())));
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function applyBatch(SeedContext $context, AppliedBatch $batch): BatchResult
    {
        $slugs = array_map(static fn (RowChange $change): string => (string) $change->key->components[0], $batch->changes);
        $known = $this->catalog->categories($slugs, true);
        $created = $updated = $deleted = 0;
        foreach ($batch->changes as $change) {
            $slug = (string) $change->key->components[0];
            if ($change->op === ChangeOp::Delete) {
                if (isset($known[$slug])) {
                    $this->catalog->removeCategory($known[$slug]);
                    ++$deleted;
                }

                continue;
            }
            $item        = $batch->item($change);
            $data        = $item !== null ? $item->data : [];
            $description = $data['description'] ?? null;
            $this->catalog->saveCategory($slug, is_string($data['label'] ?? null) ? $data['label'] : $slug, is_string($description) ? $description : null, $known[$slug] ?? null);
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
        $found = $this->catalog->categories(array_map(static fn (NaturalKey $key): string => (string) $key->components[0], $keys), $options->lockForUpdate);
        $rows  = [];
        foreach ($keys as $key) {
            $category = $found[(string) $key->components[0]] ?? null;
            if ($category !== null) {
                $rows[$key->canonical] = $this->row($category->getSlug(), $category->getLabel(), $category->getDescription());
            }
        }

        return $rows;
    }

    /**
     * The current values of a row (an empty description is absent).
     *
     * @return array<string, string>
     */
    private function row(string $slug, string $label, ?string $description): array
    {
        $row = ['slug' => $slug, 'label' => $label];
        if ($description !== null) {
            $row['description'] = $description;
        }

        return $row;
    }
}
