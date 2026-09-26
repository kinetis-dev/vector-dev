<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\Version;

#[Entity(table: 'orders')]
final class Order
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $number;

    #[BelongsTo]
    private Customer $customer;

    private OrderStatus $status;

    private int $totalCents = 0;

    private string $currency;

    private DateTimeImmutable $placedAt;

    private ?DateTimeImmutable $shippedAt = null;

    private ?string $notes = null;

    /** @var list<OrderLine> */
    #[HasMany(target: OrderLine::class, mappedBy: 'order', owned: true)]
    private array $lines;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    #[Version]
    private int $version = 1;

    public function __construct(string $number, Customer $customer, string $currency, DateTimeImmutable $placedAt)
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->number = $number;
        $this->customer = $customer;
        $this->currency = $currency;
        $this->placedAt = $placedAt;
        $this->status = OrderStatus::Pending;
        $this->totalCents = 0;
        $this->lines = [];
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function add(Product $product, int $quantity): void
    {
        $this->lines[] = new OrderLine($this, $product, $quantity, $product->priceCents());
        $this->totalCents += $quantity * $product->priceCents();
    }

    /** The total its lines add up to, after the lines were written as a whole. */
    public function recalculate(): void
    {
        $this->totalCents = array_sum(array_map(static fn (OrderLine $line): int => $line->subtotalCents(), $this->lines));
    }

    public function pay(): void
    {
        $this->status = OrderStatus::Paid;
    }

    public function ship(DateTimeImmutable $shippedAt): void
    {
        $this->status = OrderStatus::Shipped;
        $this->shippedAt = $shippedAt;
    }

    public function cancel(?string $reason): void
    {
        $this->status = OrderStatus::Cancelled;
        $this->notes = $reason;
    }
}
