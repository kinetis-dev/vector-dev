<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\Id;

/**
 * A support team. The support desk lives on its own PostgreSQL database,
 * the named connection "support": every entity of this namespace names
 * it, and none has a relationship to the MySQL domain.
 */
#[Entity(table: 'support_teams', connection: 'support')]
final class Team
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $name;

    private string $slug;

    private ?string $email = null;

    /** @var list<Agent> */
    #[HasMany(target: Agent::class, mappedBy: 'team')]
    private array $agents; // @phpstan-ignore property.onlyRead (inverse side, loaded by the ORM)

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $slug, ?string $email = null)
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->name = $name;
        $this->slug = $slug;
        $this->email = $email;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function name(): string
    {
        return $this->name;
    }
}
