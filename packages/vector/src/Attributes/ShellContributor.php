<?php

declare(strict_types=1);

namespace Kinetis\Vector\Attributes;

use Attribute;

/**
 * Marks a class implementing Http\ShellContributorInterface for
 * discovery: a package that builds on Vector tells the admin, per user,
 * what it offers. What the class answers is published in the descriptor
 * as `capabilities.<name>`. Compile-time data, like #[Settings]: the
 * registry reads it under `kinetis build` (and at boot in development),
 * never at request time.
 *
 * @param string $name the key under `capabilities`: lower-case letters, digits and dashes, starting with a letter, at most 40 characters, and none of the built-in capability keys
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ShellContributor
{
    public const string NAME = '/^[a-z][a-z0-9-]{0,39}$/D';

    public function __construct(public string $name) {}
}
