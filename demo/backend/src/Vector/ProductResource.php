<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\Product;
use App\Vector\Actions\AdjustStock;
use App\Vector\Actions\ArchiveProducts;
use App\Vector\Actions\CatalogSummary;
use App\Vector\Actions\PublishProducts;
use App\Vector\Actions\RepriceProducts;
use App\Vector\Widgets\ProductsByStatus;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Badge;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Image;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Fields\Layout\Section;
use Kinetis\Vector\Fields\Layout\Tabs;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Markdown;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Slug;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/**
 * The one demo resource with explicit fields: it shows the authoring
 * model where derivation cannot know the intent (money, markdown, slug,
 * image), and hides the columns an admin never edits by hand.
 */
#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name')]
final class ProductResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            // the layout places fields by name; the fields themselves follow in their own order
            Section::make('Details')->fields(['sku', 'name', 'slug', 'status', 'category']),
            Section::make('Pricing and stock')
                ->description('Prices are in the product\'s own currency.')
                ->collapsible()
                ->fields(['priceCents', 'currency', 'stock', 'weightKg']),
            Tabs::make()
                ->tab('Content', ['description', 'attributes', 'imageKey'])
                ->tab('Publishing', ['isFeatured', 'releasedAt', 'tags']),
            Text::make('sku', 'SKU')->required()->max(40)->sortable()->searchable()->filterable(),
            Text::make('name')->required()->max(200)->sortable()->searchable()->filterable(),
            Slug::make('slug')->from('name')->required()->max(220)->hideFromIndex(),
            Enum::make('status')->of(\App\Domain\ProductStatus::class)
                ->colors(['draft' => 'warning', 'active' => 'success', 'archived' => 'grey'])
                ->sortable()->filterable()->inlineEditable(),
            BelongsTo::to('category', CategoryResource::class)->sortable()->filterable(),
            Money::make('priceCents', 'Price')->currencyField('currency')->sortable()->filterable()->summarize('avg')->inlineEditable(),
            Currency::make('currency')->allowed(['EUR', 'USD', 'GBP'])->filterable()->hideFromIndex(),
            Number::make('stock')->sortable()->filterable()->summarize('sum')->component('stock-level')->inlineEditable(),
            Number::make('weightKg', 'Weight (kg)')->decimals(3)->hideFromIndex(),
            Boolean::make('isFeatured', 'Featured')->sortable()->filterable()->inlineEditable()
                ->disabledWhen('status', 'eq', 'archived'),
            Markdown::make('description')->hideFromIndex()->columnSpan('full'),
            KeyValue::make('attributes')->columns('Attribute', 'Value')->hideFromIndex()->columnSpan('full'),
            Image::make('imageKey', 'Image')->hideFromIndex(),
            ManyToMany::to('tags', TagResource::class),
            DateTime::make('releasedAt')->sortable()->filterable()
                ->visibleWhen('status', 'ne', 'draft')
                ->requiredWhen('status', 'eq', 'active'),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
            DateTime::make('updatedAt')->readonly()->hideFromForms()->hideFromIndex(),
        ];
    }

    public function badge(): Badge
    {
        return Badge::count(['status' => 'active', 'stock' => ['lte' => 10]], 'warning', 'active products low on stock');
    }

    public function searchSubtitle(): string
    {
        return 'sku';
    }

    public function searchImage(): string
    {
        return 'imageKey';
    }

    public function defaultSort(): array
    {
        return ['-createdAt'];
    }

    /** Small on purpose: an export of the whole catalogue shows the queued path. */
    public function exportLimit(): int
    {
        return 100;
    }

    public function softDeletes(): string
    {
        return 'deletedAt';
    }

    public function actions(): array
    {
        return [PublishProducts::class, ArchiveProducts::class, AdjustStock::class, RepriceProducts::class, CatalogSummary::class];
    }

    public function widgets(): array
    {
        return [ProductsByStatus::class];
    }
}
