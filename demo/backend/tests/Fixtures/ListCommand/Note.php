<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\ListCommand;

use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

/** ListCommandTest's project of one entity: never read from a database. */
#[Entity(table: 'notes')]
final class Note
{
    #[Id(generated: true)]
    private ?int $id = null;

    public function __construct(private string $title) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}
