<?php

declare(strict_types=1);

namespace App\Domain;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'order_lines')]
final class OrderLine
{
    #[Id(generated: true)]
    private ?int $id = null;

    #[BelongsTo]
    private Order $order;

    #[BelongsTo]
    private Product $product;

    private int $quantity;

    private int $unitPriceCents;

    /** The line's place in its order, which the lines repeater writes when its rows are reordered. */
    private int $position = 0;

    public function __construct(Order $order, Product $product, int $quantity, int $unitPriceCents, int $position = 0)
    {
        $this->order = $order;
        $this->product = $product;
        $this->quantity = $quantity;
        $this->unitPriceCents = $unitPriceCents;
        $this->position = $position;
    }

    public function subtotalCents(): int
    {
        return $this->quantity * $this->unitPriceCents;
    }
}
