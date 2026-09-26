<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\EntityManager;
use Kinetis\Vector\Registry\ResourceDescriptor;

/** What a resource's write hooks receive: the request's user and manager, and the raw input. */
final readonly class WriteContext
{
    /** @param array<string, mixed> $input the request body as received, after validation */
    public function __construct(
        public ResourceDescriptor $resource,
        public CurrentUserInterface $user,
        public EntityManager $entities,
        public array $input,
    ) {}
}
