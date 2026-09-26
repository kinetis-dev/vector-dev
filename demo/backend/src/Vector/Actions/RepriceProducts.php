<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use App\Domain\Product;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Fields\Number;

/** A queued action: changes prices by a percentage in chunks, in a worker, however many products are selected. */
final class RepriceProducts extends VectorAction
{
    public function label(): string
    {
        return 'Reprice';
    }

    public function icon(): string
    {
        return 'mdi-percent-outline';
    }

    public function queued(): bool
    {
        return true;
    }

    public function chunkSize(): int
    {
        return 50;
    }

    public function fields(): array
    {
        return [
            Number::make('percent', 'Change by %')->decimals(1)->required()
                ->rule(\Kinetis\Validation\Constraints\GreaterThanOrEqual::class, -90)
                ->rule(\Kinetis\Validation\Constraints\LessThanOrEqual::class, 500)
                ->help('Negative lowers prices. Runs in the background; you are notified when it finishes.'),
        ];
    }

    public function confirmation(): string
    {
        return 'Reprice the selected products? Prices are rounded to the cent.';
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        $percent = $request->field('percent');

        if (!is_float($percent) && !is_int($percent)) {
            return ActionResponse::danger('A percentage is required.');
        }

        foreach ($request->entities() as $product) {
            if ($product instanceof Product) {
                $product->reprice((int) round($product->priceCents() * (1 + $percent / 100)));
            }
        }

        return ActionResponse::message(sprintf('%d products repriced by %s%%.', $request->count(), $percent));
    }
}
