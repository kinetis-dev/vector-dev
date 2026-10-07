<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\Tag;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Color;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\ManyToMany;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

#[Resource(entity: Tag::class, group: 'Catalog', icon: 'mdi-tag-outline', title: 'name')]
final class TagResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('name')->required()->max(60)->unique()->sortable()->searchable()->filterable(),
            Color::make('color')->required(),
            ManyToMany::to('products', ProductResource::class)->hideFromForms(),
        ];
    }
}
