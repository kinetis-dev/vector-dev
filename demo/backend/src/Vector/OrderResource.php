<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\Order;
use App\Domain\OrderStatus;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\Currency;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Fields\HasMany;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Money;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Fields\Textarea;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\WriteContext;

/**
 * An order is written with its lines: the lines repeater sends the whole
 * list with the order, and the total is what the lines add up to.
 */
#[Resource(entity: Order::class, group: 'Sales', icon: 'mdi-cart-outline', title: 'number')]
final class OrderResource extends VectorResource
{
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('number')->required()->max(40)->sortable()->searchable()->filterable(),
            BelongsTo::to('customer', CustomerResource::class)->sortable()->filterable(),
            Enum::make('status')->of(OrderStatus::class)
                ->colors(['pending' => 'warning', 'paid' => 'info', 'shipped' => 'success', 'cancelled' => 'grey'])
                ->sortable()->filterable(),
            Money::make('totalCents', 'Total')->currencyField('currency')->readonly()->sortable()->filterable()->summarize('sum'),
            Currency::make('currency')->allowed(['EUR', 'USD', 'GBP'])->filterable()->required()->hideFromIndex(),
            DateTime::make('placedAt')->required()->sortable()->filterable(),
            DateTime::make('shippedAt')->sortable()->filterable()->hideFromIndex(),
            Textarea::make('notes')->hideFromIndex(),
            HasMany::to('lines', OrderLineResource::class)->repeater(['product', 'quantity', 'unitPriceCents'], min: 1, max: 50),
            DateTime::make('createdAt')->readonly()->hideFromForms()->hideFromIndex(),
            DateTime::make('updatedAt')->readonly()->hideFromForms()->hideFromIndex(),
        ];
    }

    public function defaultSort(): array
    {
        return ['-placedAt'];
    }

    public function beforeCreate(object $entity, WriteContext $context): void
    {
        $this->total($entity, $context);
    }

    public function beforeUpdate(object $entity, WriteContext $context): void
    {
        $this->total($entity, $context);
    }

    /** Only a write that sent the lines holds all of them. */
    private function total(object $entity, WriteContext $context): void
    {
        if ($entity instanceof Order && array_key_exists('lines', $context->input)) {
            $entity->recalculate();
        }
    }
}
