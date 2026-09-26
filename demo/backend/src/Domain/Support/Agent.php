<?php

declare(strict_types=1);

namespace App\Domain\Support;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Date;

#[Entity(table: 'agents', connection: 'support')]
final class Agent
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $name;

    private string $email;

    #[BelongsTo]
    private Team $team;

    private AgentRole $role;

    private bool $isActive;

    /** A calendar day: joined_on is a DATE column. */
    private ?Date $joinedOn = null;

    private DateTimeImmutable $createdAt;

    private DateTimeImmutable $updatedAt;

    public function __construct(string $name, string $email, Team $team, AgentRole $role, Date $joinedOn)
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->name = $name;
        $this->email = $email;
        $this->team = $team;
        $this->role = $role;
        $this->isActive = true;
        $this->joinedOn = $joinedOn;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function team(): Team
    {
        return $this->team;
    }

    public function deactivate(): void
    {
        $this->isActive = false;
    }
}
