<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Vector\Settings\SettingsGroup;

/** Typed, read-only access to one compiled settings group. */
final readonly class SettingsDescriptor
{
    /** @var array<string, array<string, mixed>> field name => compiled field */
    private array $fields;

    /** @param array<string, mixed> $data */
    public function __construct(private array $data)
    {
        $fields = [];

        foreach ($data['fields'] as $field) {
            $fields[$field['name']] = $field;
        }

        $this->fields = $fields;
    }

    public function name(): string
    {
        return $this->data['name'];
    }

    public function label(): string
    {
        return $this->data['label'];
    }

    /** @return class-string<SettingsGroup> */
    public function groupClass(): string
    {
        return $this->data['class'];
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return array<string, mixed>|null */
    public function field(string $name): ?array
    {
        return $this->fields[$name] ?? null;
    }

    /**
     * The values a group holds before anything was saved.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields as $name => $field) {
            $defaults[$name] = match (true) {
                $field['hasDefault'] === true => $field['default'],
                $field['type'] === 'boolean' => false,
                default => null,
            };
        }

        return $defaults;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $public = $this->data;
        unset($public['class']);
        $public['fields'] = array_map(ResourceDescriptor::publicField(...), $public['fields']);

        return $public;
    }
}
