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
use stdClass;

/**
 * The widest entity of the demo domain: every scalar field type Vector
 * administers, a money value in minor units, four JSON columns (an object
 * of text values, a list of sales channels, a list of spec rows and a
 * list of free-text keywords), a stored-file key, soft deletion,
 * timestamps and optimistic locking.
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

    /** an object of text values, a key-value field's: a stdClass property keeps {} an object */
    private ?stdClass $attributes = null;

    /** @var list<string>|null the sales channels, a multiple select's JSON list */
    private ?array $channels = null;

    /** @var list<array{label: string, value: string}>|null the specification rows, a JSON repeater's list */
    private ?array $specs = null;

    /** @var list<string>|null free-text keywords, a tag field's JSON list */
    private ?array $keywords = null;

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

    /** @param stdClass|null $attributes an object of text values */
    public function describe(?string $description, ?stdClass $attributes, ?string $imageKey): void
    {
        $this->description = $description;
        $this->attributes = $attributes;
        $this->imageKey = $imageKey;
    }

    /** @param list<string>|null $channels */
    public function sellOn(?array $channels): void
    {
        $this->channels = $channels;
    }

    /**
     * @param list<array{label: string, value: string}>|null $specs
     * @param list<string>|null $keywords
     */
    public function specify(?array $specs, ?array $keywords): void
    {
        $this->specs = $specs;
        $this->keywords = $keywords;
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
