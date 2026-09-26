<?php

declare(strict_types=1);

namespace App\Vector\Support;

use App\Domain\Support\Team;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\VectorResource;

/** Derived from the entity, on the PostgreSQL connection "support". */
#[Resource(entity: Team::class, slug: 'support-teams', label: 'Teams', group: 'Support', icon: 'mdi-account-group-outline', title: 'name')]
final class TeamResource extends VectorResource {}
