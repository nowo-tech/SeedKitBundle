<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

use function array_values;
use function is_numeric;
use function number_format;

/**
 * The demo's application service over its two entities (Category, Product): the seed providers write only through it,
 * never with raw SQL. It keeps no state between calls (the entity manager is the only collaborator; the providers
 * clear it after every batch), so it is safe in FrankenPHP worker mode.
 *
 * Reads take the pessimistic write lock when asked (`SELECT … FOR UPDATE`, which needs the transaction the bundle
 * opened around the batch); lookups are by natural key (slug, SKU), one query per batch.
 */
final readonly class CatalogService
{
    /**
     * Builds the service over the demo entity manager.
     */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * The categories of these slugs, by slug.
     *
     * @param list<string> $slugs
     *
     * @return array<string, Category>
     */
    public function categories(array $slugs, bool $lock = false): array
    {
        if ($slugs === []) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('c')->from(Category::class, 'c')->where('c.slug IN (:slugs)')->orderBy('c.slug', 'ASC')->setParameter('slugs', $slugs)->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $found = [];
        foreach ($query->getResult() as $category) {
            $found[$category->getSlug()] = $category;
        }

        return $found;
    }

    /**
     * The products of these SKUs, by SKU.
     *
     * @param list<string> $skus
     *
     * @return array<string, Product>
     */
    public function products(array $skus, bool $lock = false): array
    {
        if ($skus === []) {
            return [];
        }
        $query = $this->em->createQueryBuilder()->select('p', 'c')->from(Product::class, 'p')->join('p.category', 'c')->where('p.sku IN (:skus)')->orderBy('p.sku', 'ASC')->setParameter('skus', $skus)->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }
        $found = [];
        foreach ($query->getResult() as $product) {
            $found[$product->getSku()] = $product;
        }

        return $found;
    }

    /**
     * Every category, ordered by slug (byte order: the column is binary).
     *
     * @return list<Category>
     */
    public function allCategories(): array
    {
        return array_values($this->em->createQueryBuilder()->select('c')->from(Category::class, 'c')->orderBy('c.slug', 'ASC')->getQuery()->getResult());
    }

    /**
     * Every product (with its category), ordered by SKU (byte order: the column is binary).
     *
     * @return list<Product>
     */
    public function allProducts(): array
    {
        return array_values($this->em->createQueryBuilder()->select('p', 'c')->from(Product::class, 'p')->join('p.category', 'c')->orderBy('p.sku', 'ASC')->getQuery()->getResult());
    }

    /**
     * Creates or updates a category (the caller flushes through {@see flush()} inside the batch transaction).
     */
    public function saveCategory(string $slug, string $label, ?string $description, ?Category $existing): void
    {
        if ($existing === null) {
            $this->em->persist(new Category($slug, $label, $description));

            return;
        }
        $existing->setLabel($label);
        $existing->setDescription($description);
    }

    /**
     * Creates or updates a product.
     */
    public function saveProduct(string $sku, string $name, Category $category, float|int $price, bool $active, ?Product $existing): void
    {
        $decimal = number_format((float) $price, 2, '.', '');
        if ($existing === null) {
            $this->em->persist(new Product($sku, $name, $category, $decimal, $active));

            return;
        }
        $existing->setName($name);
        $existing->setCategory($category);
        $existing->setPrice($decimal);
        $existing->setActive($active);
    }

    /**
     * Removes a category.
     */
    public function removeCategory(Category $category): void
    {
        $this->em->remove($category);
    }

    /**
     * Removes a product.
     */
    public function removeProduct(Product $product): void
    {
        $this->em->remove($product);
    }

    /**
     * Writes the pending changes inside the transaction the bundle opened (no commit here).
     */
    public function flush(): void
    {
        $this->em->flush();
    }

    /**
     * Detaches everything (the providers call it after every batch: memory stays flat, no stale entity is reused).
     */
    public function clear(): void
    {
        $this->em->clear();
    }

    /**
     * The row counts shown on the home page.
     *
     * @return array{categories: int, products: int}
     */
    public function counts(): array
    {
        $categories = $this->em->createQuery('SELECT COUNT(c.id) FROM ' . Category::class . ' c')->getSingleScalarResult();
        $products   = $this->em->createQuery('SELECT COUNT(p.id) FROM ' . Product::class . ' p')->getSingleScalarResult();

        return ['categories' => is_numeric($categories) ? (int) $categories : 0, 'products' => is_numeric($products) ? (int) $products : 0];
    }
}
