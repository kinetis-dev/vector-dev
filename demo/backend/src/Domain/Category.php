<?php

declare(strict_types=1);

namespace App\Domain;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'categories')]
final class Category
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $name;

    private string $slug;

    private ?string $description = null;

    private int $position;

    #[BelongsTo]
    private ?Category $parent;

    /** @var list<Product> */
    #[HasMany(target: Product::class, mappedBy: 'category')]
    private array $products; // @phpstan-ignore property.onlyRead (assigned by the ORM when the relationship is loaded)

    public function __construct(string $name, string $slug, int $position, ?Category $parent = null, ?string $description = null)
    {
        $this->name = $name;
        $this->slug = $slug;
        $this->position = $position;
        $this->parent = $parent;
        $this->description = $description;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }
}
