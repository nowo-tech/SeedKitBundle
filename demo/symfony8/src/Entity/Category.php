<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A catalog category of the demo (natural key: the lower-cased slug).
 */
#[ORM\Entity]
#[ORM\Table(name: 'demo_category')]
#[ORM\UniqueConstraint(name: 'uniq_demo_category_slug', columns: ['slug'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(length: 64, options: ['collation' => 'utf8mb4_bin'])]
        private string $slug,
        #[ORM\Column(length: 120)]
        private string $label,
        #[ORM\Column(length: 500, nullable: true)]
        private ?string $description = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
    }
}
