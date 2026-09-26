<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\OrderLine;
use Kinetis\Validation\Constraints\GreaterThan;
use Kinetis\Validation\Constraints\GreaterThanOrEqual;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\VectorResource;

#[Resource(entity: OrderLine::class, slug: 'order-lines', label: 'Order lines', group: 'Sales', icon: 'mdi-format-list-numbered')]
final class OrderLineResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::to('order', OrderResource::class)->sortable()->filterable(),
            BelongsTo::to('product', ProductResource::class)->sortable()->filterable(),
            Number::make('quantity')->required()->rule(GreaterThan::class, 0)->sortable()->filterable()->summarize('sum'),
            Money::make('unitPriceCents', 'Unit price')->currencyField('order.currency')->required()->rule(GreaterThanOrEqual::class, 0)->sortable()->filterable(),
        ];
    }
}
