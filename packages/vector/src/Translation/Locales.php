<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

/**
 * The languages Vector's UI ships — English, the base and every other
 * locale's fallback, and sixteen more — with their names in themselves and
 * their direction. Which of them users may pick, and the default, is the
 * administrator's choice in the Languages settings group.
 */
final class Locales
{
    /**
     * The UI's own locales: tag => its name in itself, in the order every
     * list of languages shows them — the app bar's menu, the Languages
     * settings, the default-language choice. That order is the names'
     * Unicode root collation (CLDR's language-neutral order): Latin-script
     * names alphabetically, accents and case aside, then each other script
     * as a group in the standard's order — Greek, Cyrillic, Hebrew, Arabic,
     * the Indic scripts, Thai, Hangul, Kana, Han. It is the same whatever
     * language the admin is shown in, so a list never reorders on a switch,
     * and nothing is pinned first. A language added here goes where that
     * collation puts its name; LanguageOrderTest fails otherwise.
     */
    public const array SHIPPED = [
        'de' => 'Deutsch',
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt-BR' => 'Português (Brasil)',
        'pt' => 'Português (Portugal)',
        'tr' => 'Türkçe',
        'ru' => 'Русский',
        'uk' => 'Українська',
        'he' => 'עברית',
        'ar' => 'العربية',
        'ko' => '한국어',
        'ja' => '日本語',
        'zh-Hans' => '简体中文',
    ];

    /** Languages written right to left. */
    public const array RTL = ['ar', 'fa', 'he', 'ur', 'yi'];

    /** @return array{code: string, name: string, dir: 'ltr'|'rtl'} */
    public static function describe(string $tag): array
    {
        $language = strtolower(strtok($tag, '-') ?: $tag);

        return [
            'code' => $tag,
            'name' => self::SHIPPED[$tag] ?? self::SHIPPED[$language] ?? self::displayName($tag),
            'dir' => in_array($language, self::RTL, true) ? 'rtl' : 'ltr',
        ];
    }

    /** The tag's own name for itself, where intl is installed; the tag itself otherwise. */
    private static function displayName(string $tag): string
    {
        if (!class_exists(\Locale::class)) {
            return $tag;
        }

        return \Locale::getDisplayName($tag, $tag) ?: $tag;
    }

    /**
     * The index in `$among` of the tag best matching `$tag`: the same tag,
     * else the same language.
     *
     * @param list<string> $among
     */
    public static function match(string $tag, array $among): ?int
    {
        $lower = array_map('strtolower', $among);
        $exact = array_search(strtolower($tag), $lower, true);

        if ($exact !== false) {
            return $exact;
        }

        $language = strtolower(strtok($tag, '-') ?: $tag);

        foreach ($lower as $index => $candidate) {
            if ((strtok($candidate, '-') ?: $candidate) === $language) {
                return $index;
            }
        }

        return null;
    }
}
