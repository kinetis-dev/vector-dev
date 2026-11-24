<?php

declare(strict_types=1);

namespace App\Vector;

use App\Domain\CustomerProfile;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Fields\BelongsTo;
use Kinetis\Vector\Fields\BooleanGroup;
use Kinetis\Vector\Fields\Country;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\VectorResource;

/**
 * A customer's address: the country as an ISO 3166-1 code, and the three
 * ways the customer may be contacted as one flag group over three bool
 * columns, each of which also filters and sorts on its own
 * (`filter[contact.acceptsSms]=true`, `sort=contact.newsletter`).
 */
#[Resource(entity: CustomerProfile::class, slug: 'customer-profiles', label: 'Customer profiles', group: 'Sales', icon: 'mdi-map-marker-outline', title: 'city')]
final class CustomerProfileResource extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [
            Id::make(),
            BelongsTo::to('customer', CustomerResource::class)->sortable(),
            Text::make('addressLine')->required()->max(200)->sortable()->searchable(),
            Text::make('city')->required()->max(120)->sortable()->searchable(),
            Text::make('postalCode')->required()->max(20)->sortable()->searchable(),
            Country::make('country')->required()->sortable()->filterable(),
            BooleanGroup::make('contact', 'Contact by')
                ->options(['newsletter' => 'Newsletter', 'acceptsSms' => 'Text message', 'acceptsPost' => 'Post'])
                ->sortable()->filterable(),
        ];
    }
}
