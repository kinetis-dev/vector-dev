<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\HasMany;
use Kinetis\Orm\Attributes\HasOne;
use Kinetis\Orm\Attributes\Id;
use Kinetis\Orm\Date;

#[Entity(table: 'customers')]
final class Customer
{
    #[Id(generated: true)]
    private ?int $id = null;

    private string $email;

    private string $name;

    private ?string $phone = null;

    private ?string $website = null;

    private ?Date $birthDate = null;

    private ?string $notes = null;

    #[HasOne(mappedBy: 'customer', owned: true)]
    private ?CustomerProfile $profile;

    /** @var list<Order> */
    #[HasMany(target: Order::class, mappedBy: 'customer')]
    private array $orders; // @phpstan-ignore property.onlyRead (assigned by the ORM when the relationship is loaded)

    private DateTimeImmutable $createdAt;

    public function __construct(string $email, string $name)
    {
        $this->email = $email;
        $this->name = $name;
        $this->createdAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function contact(?string $phone, ?string $website, ?Date $birthDate, ?string $notes): void
    {
        $this->phone = $phone;
        $this->website = $website;
        $this->birthDate = $birthDate;
        $this->notes = $notes;
    }

    public function profile(CustomerProfile $profile): void
    {
        $this->profile = $profile;
    }
}
