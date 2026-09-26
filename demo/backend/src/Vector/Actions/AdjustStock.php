<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use App\Domain\Product;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Textarea;

/** A dialog with a required number and a note; refuses to take stock below zero. */
final class AdjustStock extends VectorAction
{
    public function label(): string
    {
        return 'Adjust stock';
    }

    public function icon(): string
    {
        return 'mdi-package-variant-plus';
    }

    public function fields(): array
    {
        return [
            Number::make('delta', 'Change by')->required()->help('Negative removes stock.'),
            Textarea::make('note')->max(200),
        ];
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        $delta = $request->field('delta');

        if (!is_int($delta)) {
            return ActionResponse::danger('A whole number is required.');
        }

        foreach ($request->entities() as $product) {
            if ($product instanceof Product && !$product->adjustStock($delta)) {
                return ActionResponse::danger("Stock of {$product->name()} cannot go below zero.");
            }
        }

        return ActionResponse::message("Stock changed by {$delta} on {$request->count()} products.");
    }
}
