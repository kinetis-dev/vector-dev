<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ListCommand;

use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/** A resource in no navigation group, so vector:list shows its empty cell. */
#[Resource(entity: Note::class, title: 'title')]
final class NoteResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            Text::make('title')->required(),
        ];
    }
}
