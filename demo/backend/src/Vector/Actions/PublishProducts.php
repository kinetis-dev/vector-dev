<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use App\Domain\Product;
use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\DateTime;

/** Activates the selected products through the entity's own method, with a dialog for the release date. */
final class PublishProducts extends VectorAction
{
    #[\Override]
    public function label(): string
    {
        return 'Publish';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-rocket-launch-outline';
    }

    #[\Override]
    public function fields(): array
    {
        return [
            DateTime::make('releasedAt', 'Release date')->help('Empty publishes now.'),
            Boolean::make('featured', 'Feature on the storefront')->default(false),
        ];
    }

    #[\Override]
    public function confirmation(): string
    {
        return 'Publish the selected products? They become visible to customers.';
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        $releasedAt = $request->field('releasedAt') ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $featured = $request->field('featured') === true;

        foreach ($request->entities() as $product) {
            if ($product instanceof Product && $releasedAt instanceof DateTimeImmutable) {
                $product->activate($releasedAt, $featured);
            }
        }

        return ActionResponse::message(sprintf('%d product%s published.', $request->count(), $request->count() === 1 ? '' : 's'));
    }
}
