<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\Label;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Color;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

#[Resource(entity: Label::class, group: 'Support', icon: 'mdi-label-outline', title: 'name')]
final class LabelResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(60)->sortable()->searchable()->filterable(),
            Color::make('color')->required(),
        ];
    }
}
