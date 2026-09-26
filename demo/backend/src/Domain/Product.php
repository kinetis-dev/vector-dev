<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Attributes\ManyToMany;
use Kinetis\Orm\Attributes\Version;

/**
 * The widest entity of the demo domain: every scalar field type Vector
 * administers, a money value in minor units, a JSON column held as text,
 * a stored-file key, soft deletion, timestamps and optimistic locking.
 */
#[Entity(table: 'products')]
final class Product
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $sku;

    private string $name;

    private string $slug;

    private ?string $description = null;

    private ProductStatus $status;

    private int $priceCents;

    private string $currency;

    private int $stock;

    private float $weightKg;

    private bool $isFeatured;

    private ?string $attributes = null;

    private ?string $imageKey = null;

    private ?DateTimeImmutable $releasedAt = null;

    #[BelongsTo]
    private Category $category;

    /** @var list<Tag> */
    #[ManyToMany(target: Tag::class, table: 'product_tag', joinColumn: 'product_id', inverseJoinColumn: 'tag_id')]
    private array $tags;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    private ?DateTimeImmutable $deletedAt = null;

    #[Version]
    private int $version = 1;

    public function __construct(
        string $sku,
        string $name,
        string $slug,
        Category $category,
        int $priceCents,
        string $currency,
        int $stock,
        float $weightKg,
    ) {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->sku = $sku;
        $this->name = $name;
        $this->slug = $slug;
        $this->category = $category;
        $this->priceCents = $priceCents;
        $this->currency = $currency;
        $this->stock = $stock;
        $this->weightKg = $weightKg;
        $this->status = ProductStatus::Draft;
        $this->isFeatured = false;
        $this->tags = [];
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function priceCents(): int
    {
        return $this->priceCents;
    }

    public function describe(?string $description, ?string $attributesJson, ?string $imageKey): void
    {
        $this->description = $description;
        $this->attributes = $attributesJson;
        $this->imageKey = $imageKey;
    }

    public function activate(DateTimeImmutable $releasedAt, bool $featured): void
    {
        $this->status = ProductStatus::Active;
        $this->releasedAt = $releasedAt;
        $this->isFeatured = $featured;
    }

    public function archive(): void
    {
        $this->status = ProductStatus::Archived;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function reprice(int $priceCents): void
    {
        $this->priceCents = max(0, $priceCents);
    }

    /** False when the change would take stock below zero; nothing changes then. */
    public function adjustStock(int $delta): bool
    {
        if ($this->stock + $delta < 0) {
            return false;
        }

        $this->stock += $delta;

        return true;
    }

    /** @param list<Tag> $tags */
    public function tag(array $tags): void
    {
        $this->tags = $tags;
    }
}
