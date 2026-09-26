<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\CustomerProfile;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

#[Resource(entity: CustomerProfile::class, slug: 'customer-profiles', label: 'Customer profiles', group: 'Sales', icon: 'mdi-map-marker-outline', title: 'city')]
final class CustomerProfileResource extends VectorResource {}
