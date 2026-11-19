<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Settings;

/**
 * What a model may be sent of a record by default. `send`: every field
 * except one a resource or field opts out of with meta('ai.send', false).
 * `allowlist`: only what opts in with meta('ai.send', true).
 */
enum DataDefault: string
{
    case Send = 'send';
    case Allowlist = 'allowlist';
}
