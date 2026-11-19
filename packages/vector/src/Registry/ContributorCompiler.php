<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\Attributes\ShellContributor;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Http\ShellContributorInterface;
use ReflectionClass;

/**
 * Compiles the #[ShellContributor] classes into the map the schema
 * endpoint resolves at request time: capability name => class. A name
 * must be a short token, unique among contributors, and none of the keys
 * GET /vector/schema publishes itself, so a package can never shadow a
 * built-in capability.
 */
final class ContributorCompiler
{
    /** The capability keys GET /vector/schema publishes itself. */
    public const array BUILT_IN = ['impersonation', 'queue', 'queues', 'failedJobs', 'queueMetrics', 'exportFormats', 'uploads'];

    /**
     * @param list<class-string> $classes every class carrying #[ShellContributor]
     * @return array<string, class-string<ShellContributorInterface>> name => class, ordered by name
     * @throws ResourceCompileException
     */
    public static function compile(array $classes): array
    {
        $contributors = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $attribute = $reflection->getAttributes(ShellContributor::class)[0] ?? throw ResourceCompileException::resource($class, 'carries no #[ShellContributor] attribute');
            $name = $attribute->newInstance()->name;

            if (!$reflection->implementsInterface(ShellContributorInterface::class) || !$reflection->isInstantiable()) {
                throw ResourceCompileException::resource($class, 'carries #[ShellContributor] but is not a concrete ' . ShellContributorInterface::class);
            }

            if (preg_match(ShellContributor::NAME, $name) !== 1) {
                throw ResourceCompileException::resource($class, "#[ShellContributor] name \"{$name}\" is not lower-case letters, digits and dashes, starting with a letter, at most 40 characters");
            }

            if (in_array($name, self::BUILT_IN, true)) {
                throw ResourceCompileException::resource($class, "#[ShellContributor] name \"{$name}\" is a built-in capability");
            }

            if (isset($contributors[$name])) {
                throw ResourceCompileException::resource($class, "#[ShellContributor] name \"{$name}\" is already taken by {$contributors[$name]}");
            }

            /** @var class-string<ShellContributorInterface> $class */
            $contributors[$name] = $class;
        }

        ksort($contributors, SORT_STRING);

        return $contributors;
    }
}
