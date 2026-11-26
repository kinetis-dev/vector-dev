<?php

declare(strict_types=1);

namespace App\Domain;

use Kinetis\Orm\Attributes\BelongsTo;
use Kinetis\Orm\Attributes\Entity;
use Kinetis\Orm\Attributes\Id;

#[Entity(table: 'customer_profiles')]
final class CustomerProfile
{
    #[Id(generated: true)]
    private ?int $id = null;

    #[BelongsTo]
    private Customer $customer;

    private string $addressLine;

    private string $city;

    private string $postalCode;

    private string $country;

    private bool $newsletter;

    private bool $acceptsSms = false;

    private bool $acceptsPost = false;

    public function __construct(Customer $customer, string $addressLine, string $city, string $postalCode, string $country, bool $newsletter)
    {
        $this->customer = $customer;
        $this->addressLine = $addressLine;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->country = $country;
        $this->newsletter = $newsletter;
    }

    /** Whether the customer may be reached by text message and by post too. */
    public function reachableBy(bool $sms, bool $post): void
    {
        $this->acceptsSms = $sms;
        $this->acceptsPost = $post;
    }
}
