<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Settings\SettingsGroup;

/** Compiles every concrete SettingsGroup into the descriptor's `settings` map. */
final class SettingsCompiler
{
    /**
     * @param list<class-string<SettingsGroup>> $classes
     * @param array<string, array<string, mixed>> $resources the compiled resources, for belongsTo targets
     * @return array<string, array<string, mixed>> name => group
     */
    public static function compile(array $classes, array $resources): array
    {
        $slugByClass = [];

        foreach ($resources as $slug => $resource) {
            $slugByClass[$resource['class']] = $slug;
        }

        $groups = [];

        foreach ($classes as $class) {
            $group = (new \ReflectionClass($class))->newInstanceWithoutConstructor(); // definitions need no dependencies
            $name = $group->name();

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
                throw ResourceCompileException::resource($class, "has the settings name \"{$name}\"; a settings name is lower-case kebab-case");
            }

            if (isset($groups[$name])) {
                throw ResourceCompileException::resource($class, "and {$groups[$name]['class']} are both named \"{$name}\"");
            }

            $fields = [];

            foreach ($group->fields() as $field) {
                if (isset($fields[$field->name()])) {
                    throw ResourceCompileException::field($class, $field->name(), 'is declared twice');
                }

                $fields[$field->name()] = ResourceCompiler::compileLoose($field, $slugByClass, $class);
            }

            ResourceCompiler::checkConditions($fields, $class);

            $groups[$name] = [
                'name' => $name,
                'class' => $class,
                'label' => $group->label(),
                'icon' => $group->icon(),
                'fields' => array_values($fields),
            ];
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }
}
