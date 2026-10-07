<?php

declare(strict_types=1);

namespace Kinetis\Vector\Settings;

use Kinetis\Validation\Violation;
use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Enum;
use Kinetis\Vector\Translation\Locales;

/**
 * Which languages users may switch the admin to — all of them at first —
 * and the one it opens in for a user who has not chosen: English unless
 * changed, and always one that is switched on, so at least one language
 * is on offer. Each user picks among the enabled ones from the app bar.
 */
#[Settings]
final class Languages extends SettingsGroup
{
    #[\Override]
    public function label(): string
    {
        return 'Languages';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-translate';
    }

    #[\Override]
    public function fields(): array
    {
        $fields = [];

        foreach (Locales::SHIPPED as $tag => $name) {
            $fields[] = Boolean::make($tag, $name)->default(true);
        }

        $fields[] = Enum::make('default', 'Default language')->of(Language::class)->labels(array_intersect_key(Locales::SHIPPED, array_flip(array_column(Language::cases(), 'value'))))
            ->required()->default(Language::English->value)
            ->help('The language for a user who has not chosen one.');

        return $fields;
    }

    #[\Override]
    public function validate(array $values): array
    {
        $default = is_string($values['default'] ?? null) ? $values['default'] : Language::English->value;

        return ($values[$default] ?? true) === false
            ? [new Violation(['default'], 'invalid_choice', 'must be a language that is switched on.')]
            : [];
    }
}
