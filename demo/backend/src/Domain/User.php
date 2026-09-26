<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'users')]
final class User
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $email;

    private string $name;

    private string $passwordHash;

    private bool $isAdmin;

    private DateTimeImmutable $createdAt;

    public function __construct(string $email, string $name, string $password, bool $isAdmin)
    {
        $this->email = $email;
        $this->name = $name;
        $this->passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->isAdmin = $isAdmin;
        $this->createdAt = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isAdmin(): bool
    {
        return $this->isAdmin;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->passwordHash);
    }
}
