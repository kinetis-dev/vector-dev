<?php

declare(strict_types=1);

namespace App\Domain\Support;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'labels', connection: 'support')]
final class Label
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $name;

    private string $color;

    public function __construct(string $name, string $color)
    {
        $this->name = $name;
        $this->color = $color;
    }
}
