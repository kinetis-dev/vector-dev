<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\Customer;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

#[Resource(entity: Customer::class, group: 'Sales', icon: 'mdi-account-multiple-outline', title: 'name')]
final class CustomerResource extends VectorResource {}
