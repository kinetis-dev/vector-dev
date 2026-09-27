<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Settings\Language;
use Kinetis\Vector\Translation\Locales;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Every list of languages shows them in one order: their own names by
 * Unicode's root collation — Latin script alphabetically, accents and
 * case aside, then each other script as a group — the same in every
 * language the admin is shown in. The list ships in that order, so a
 * deployment needs no intl; this suite holds it there.
 */
final class LanguageOrderTest extends VectorTestCase
{
    #[RequiresPhpExtension('intl')]
    public function testTheShippedLanguagesAreInRootCollationOrderOfTheirOwnNames(): void
    {
        $names = array_values(Locales::SHIPPED);
        $sorted = $names;
        (new \Collator('root'))->sort($sorted);

        self::assertSame($sorted, $names, 'a language added to Locales::SHIPPED goes where Unicode root collation puts its name');
    }

    public function testTheDefaultLanguageChoiceAndTheSwitchesFollowThatOrder(): void
    {
        self::assertSame(array_keys(Locales::SHIPPED), array_map(static fn (Language $l): string => $l->value, Language::cases()));

        $group = $this->client->get('/vector/settings/languages', headers: $this->realAdminHeaders())->assertOk()->json()['data'];
        $fields = array_column($group['fields'], null, 'name');
        $switches = array_values(array_filter(array_column($group['fields'], 'name'), static fn (string $name): bool => $name !== 'default'));

        self::assertSame(array_keys(Locales::SHIPPED), $switches);
        self::assertSame(array_keys(Locales::SHIPPED), array_column($fields['default']['options'], 'value'));
    }

    public function testTheMenuListsTheLanguagesInThatOrderWhateverTheAdminIsShownIn(): void
    {
        foreach (['en', 'he', 'de'] as $shownIn) {
            $locales = $this->client->get('/vector/schema', headers: $this->headersIn($shownIn))->assertOk()->json()['i18n']['locales'];

            self::assertSame(['Deutsch', 'English', 'Español', 'Français', 'Italiano', 'Nederlands', 'Português', 'עברית'], array_column($locales, 'name'), "shown in {$shownIn}");
        }
    }
}
