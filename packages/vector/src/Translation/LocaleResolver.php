<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\PreferenceStore;
use Kinetis\Vector\Store\SettingsStore;

/**
 * The language one request is answered in: the language the user chose
 * in the app bar, while the administrator has it switched on, else the
 * administrator's default — English unless changed. The browser's
 * languages play no part: until users choose, the administrator decides.
 * Holds no state: the user is an argument.
 */
final readonly class LocaleResolver
{
    public function __construct(
        private VectorRegistry $registry,
        private SettingsStore $settings,
        private PreferenceStore $preferences,
    ) {}

    /**
     * @return array{locale: string, locales: list<array{code: string, name: string, dir: 'ltr'|'rtl'}>}
     */
    public function resolve(?string $userId): array
    {
        [$available, $default] = $this->available();
        $chosen = null;

        if ($userId !== null) {
            $preference = $this->preferences->all($userId)['locale'] ?? null;
            $index = is_string($preference) ? Locales::match($preference, $available) : null;
            $chosen = $index === null ? null : $available[$index];
        }

        return ['locale' => $chosen ?? $default, 'locales' => array_map(Locales::describe(...), $available)];
    }

    /** @return non-empty-list<string> the languages on offer: the ones the administrator switched on */
    public function languages(): array
    {
        return $this->available()[0];
    }

    /** @return array{non-empty-list<string>, string} the enabled languages, and the default among them */
    private function available(): array
    {
        $shipped = array_keys(Locales::SHIPPED);
        $settings = isset($this->registry->settings()['languages']) ? $this->settings->values($this->registry->settingsGroup('languages')) : null;
        $enabled = $settings === null ? $shipped : array_values(array_filter($shipped, static fn (string $tag): bool => ($settings[$tag] ?? true) !== false));
        $preferred = is_string($settings['default'] ?? null) ? $settings['default'] : 'en';

        if ($enabled === []) {
            return [[$preferred], $preferred]; // nothing ticked: the default alone
        }

        // a default switched off gives way to the first language still on
        return [$enabled, in_array($preferred, $enabled, true) ? $preferred : $enabled[0]];
    }
}
