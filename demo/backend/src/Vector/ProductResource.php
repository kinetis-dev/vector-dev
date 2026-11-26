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
use Kinetis\Vector\Fields\DateRange;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Image;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Fields\Layout\Section;
use Kinetis\Vector\Fields\Layout\Tabs;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\RichText;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Repeater;
use Kinetis\Vector\Fields\Select;
use Kinetis\Vector\Fields\Slug;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\FullText;
use Kinetis\Vector\VectorResource;

/**
 * The one demo resource with explicit fields: it shows the authoring
 * model where derivation cannot know the intent (money, markdown, slug,
 * image), and hides the columns an admin never edits by hand. It opts
 * in to AI (`ai.send` true, docs/ai.md) with its name, status, category
 * and stock, which is all a model is told of it once the AI settings send
 * only what opts in.
 */
#[Resource(entity: Product::class, group: 'Catalog', icon: 'mdi-package-variant', title: 'name', meta: ['ai.send' => true])]
final class ProductResource extends VectorResource
{
    /** Where a product is sold: the options of its multiple select, in the order it stores them. */
    public const array CHANNELS = ['web' => 'Web shop', 'retail' => 'Retail stores', 'marketplace' => 'Marketplaces', 'wholesale' => 'Wholesale'];

    /** Keywords the product form suggests; any other text is a keyword too. */
    public const array KEYWORDS = ['eco', 'bestseller', 'gift', 'bundle', 'clearance'];

    #[\Override]
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
                ->tab('Content', ['description', 'attributes', 'specs', 'imageKey'])
                ->tab('Publishing', ['isFeatured', 'channels', 'keywords', 'releasedAt', 'promotion', 'tags']),
            Text::make('sku', 'SKU')->placeholder('A unique stock code, such as KB-0042')->required()->max(40)->unique()->sortable()->searchable()->filterable(),
            Text::make('name')->required()->max(200)->sortable()->searchable()->filterable()->meta('ai.send', true),
            Slug::make('slug')->from('name')->required()->max(220)->unique()->hideFromIndex(),
            // shown as a coloured chip in the list and on the record
            Select::make('status')->of(\App\Domain\ProductStatus::class)
                ->badge(['draft' => 'warning', 'active' => 'success', 'archived' => 'grey'])
                ->sortable()->filterable()->inlineEditable()->meta('ai.send', true),
            // search matches the category's name too
            BelongsTo::to('category', CategoryResource::class)->sortable()->filterable()->searchable()->meta('ai.send', true),
            Money::make('priceCents', 'Price')->currencyField('currency')->sortable()->filterable()->summarize('avg')->inlineEditable(),
            Currency::make('currency')->allowed(['EUR', 'USD', 'GBP'])->filterable()->hideFromIndex(),
            Number::make('stock')->sortable()->filterable()->summarize('sum')->component('stock-level')->inlineEditable()->meta('ai.send', true),
            Number::make('weightKg', 'Weight (kg)')->decimals(3)->hideFromIndex(),
            Boolean::make('isFeatured', 'Featured')->sortable()->filterable()->inlineEditable()
                ->disabledWhen('status', 'eq', 'archived'),
            // a structured document: headings, lists, links, tables, and images uploaded through the image field
            RichText::make('description')->imageField('imageKey')->hideFromIndex()->columnSpan('full'),
            KeyValue::make('attributes')->columns('Attribute', 'Value')->hideFromIndex()->columnSpan('full'),
            // rows of a JSON column, each a label and a value, dragged into the order they are shown in
            Repeater::make('specs', 'Specifications')->fields([
                Text::make('label')->required()->max(60),
                Text::make('value')->required()->max(200),
            ])->max(20)->hideFromIndex()->columnSpan('full'),
            Image::make('imageKey', 'Image')->hideFromIndex(),
            ManyToMany::to('tags', TagResource::class),
            // several values in a JSON column, filtered by "any" or "all" of them
            Select::make('channels', 'Sales channels')->options(self::CHANNELS)->multiple()->filterable()->badge(['web' => 'primary', 'retail' => 'success', 'marketplace' => 'info', 'wholesale' => 'grey']),
            // free text in a JSON list, shown as chips and filtered by "any" or "all" of the keywords
            Tag::make('keywords')->suggestions(self::KEYWORDS)->filterable()->hideFromIndex(),
            DateTime::make('releasedAt')->sortable()->filterable()
                ->visibleWhen('status', 'ne', 'draft')
                ->requiredWhen('status', 'eq', 'active'),
            // two DATE columns edited as one period: both ends set or neither, the end never before the start
            DateRange::make('promotion', from: 'promotionStartsOn', to: 'promotionEndsOn')->minDate('2020-01-01')->hideFromIndex(),
            DateTime::make('createdAt')->readonly()->sortable()->hideFromForms(),
            DateTime::make('updatedAt')->readonly()->hideFromForms()->hideFromIndex(),
        ];
    }

    /** The name is searched by words, through the FULLTEXT index of migration 20261020000001. */
    #[\Override]
    public function fullText(): FullText
    {
        return FullText::on('name');
    }

    #[\Override]
    public function badge(): Badge
    {
        return Badge::count(['status' => 'active', 'stock' => ['lte' => 10]], 'warning', 'active products low on stock');
    }

    #[\Override]
    public function searchSubtitle(): string
    {
        return 'sku';
    }

    #[\Override]
    public function searchImage(): string
    {
        return 'imageKey';
    }

    #[\Override]
    public function defaultSort(): array
    {
        return ['-createdAt'];
    }

    /** Small on purpose: an export of the whole catalogue shows the queued path. */
    #[\Override]
    public function exportLimit(): int
    {
        return 100;
    }

    #[\Override]
    public function softDeletes(): string
    {
        return 'deletedAt';
    }

    #[\Override]
    public function actions(): array
    {
        return [PublishProducts::class, ArchiveProducts::class, AdjustStock::class, RepriceProducts::class, CatalogSummary::class];
    }

    #[\Override]
    public function widgets(): array
    {
        return [ProductsByStatus::class];
    }
}
