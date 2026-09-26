<?php

declare(strict_types=1);

namespace App\Domain;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;

#[Entity(table: 'tags')]
final class Tag
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $name;

    private string $color;

    /** @var list<Product> */
    #[ManyToMany(target: Product::class, mappedBy: 'tags')]
    private array $products; // @phpstan-ignore property.onlyRead (assigned by the ORM when the relationship is loaded)

    public function __construct(string $name, string $color)
    {
        $this->name = $name;
        $this->color = $color;
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
