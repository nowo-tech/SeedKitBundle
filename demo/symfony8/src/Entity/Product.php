<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A catalog product of the demo (natural key: the SKU).
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_product')]
#[ORM\UniqueConstraint(name: 'uniq_demo_product_sku', columns: ['sku'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64, options: ['collation' => 'utf8mb4_bin'])]
        private string $sku,
        #[ORM\Column(length: 200)]
        private string $name,
        #[ORM\ManyToOne(targetEntity: Category::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Category $category,
        #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
        private string $price,
        #[ORM\Column]
        private bool $active = true,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    public function setCategory(Category $category): void
    {
        $this->category = $category;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function setPrice(string $price): void
    {
        $this->price = $price;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }
}
