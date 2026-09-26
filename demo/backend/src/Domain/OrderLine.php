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

    public function __construct(Order $order, Product $product, int $quantity, int $unitPriceCents)
    {
        $this->order = $order;
        $this->product = $product;
        $this->quantity = $quantity;
        $this->unitPriceCents = $unitPriceCents;
    }

    public function subtotalCents(): int
    {
        return $this->quantity * $this->unitPriceCents;
    }
}
