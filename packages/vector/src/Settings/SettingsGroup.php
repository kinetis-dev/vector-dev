<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Fields\Field;

/**
 * One page of typed settings an admin edits in Vector's own UI, stored
 * as values in `vector_settings` and rendered from the same field
 * registry as a form. A concrete subclass carrying #[Settings] anywhere
 * under a scanned namespace is discovered at compile time; its
 * definition methods run on an instance built without its constructor
 * and return plain data.
 *
 * A setting is a value, never markup: what a group publishes is
 * rendered escaped by the UI, so editing settings is an admin-user
 * privilege and injecting script stays a deployer's (§4.15).
 */
abstract class SettingsGroup
{
    /** The URL segment and the key values are stored under; kebab-case of the class name by default. */
    public function name(): string
    {
        $short = (new \ReflectionClass($this))->getShortName();
        $short = preg_replace('/Settings$/', '', $short) ?? $short;

        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $short) ?? $short);
    }

    public function label(): string
    {
        return Field::labelFrom(str_replace('-', '_', $this->name()));
    }

    public function icon(): ?string
    {
        return null;
    }

    /** @return list<Field> */
    abstract public function fields(): array;

    /** Request-time: whether this user may read and change the group. The access policy already admitted them. */
    public function canManage(CurrentUserInterface $user): bool
    {
        return true;
    }

    /**
     * Request-time: what the values say together, once each field passed
     * its own rules — a rule that spans fields. Nothing is saved while one
     * violation remains.
     *
     * @param array<string, mixed> $values every field of the group, as stored
     * @return list<Violation>
     */
    public function validate(array $values): array
    {
        return [];
    }
}
