<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use App\Domain\Product;
use App\Domain\ProductStatus;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;

/** A destructive action with per-record visibility: an archived product cannot be archived again. */
final class ArchiveProducts extends VectorAction
{
    public function label(): string
    {
        return 'Archive';
    }

    public function icon(): string
    {
        return 'mdi-archive-arrow-down-outline';
    }

    public function destructive(): bool
    {
        return true;
    }

    public function canRun(CurrentUserInterface $user, ?array $record): bool
    {
        return $record === null || ($record['status'] ?? null) !== ProductStatus::Archived->value;
    }

    public function handle(ActionRequest $request): ActionResponse
    {
        foreach ($request->entities() as $product) {
            if ($product instanceof Product) {
                $product->archive();
            }
        }

        return ActionResponse::message("{$request->count()} archived.");
    }
}
