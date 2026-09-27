<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Translation\Labels;
use Kinetis\Vector\Translation\MessagesTranslator;

/**
 * Labels in the user's language: translated at response time for the
 * language the user chose — while the administrator has it switched on —
 * else the administrator's default; the browser's languages play no part.
 * The compiled registry stays in the labels' own words. Languages are set
 * on a viewer of the tests' own (headersIn()), never on the seeded admin.
 */
final class TranslationTest extends VectorTestCase
{
    public function testTheSchemaIsTranslatedIntoTheLanguageTheUserChose(): void
    {
        $schema = $this->schema($this->headersIn('de'));
        $products = $schema['resources']['products'];
        $fields = array_column($products['fields'], null, 'name');
        $status = array_column($fields['status']['options'], 'label', 'value');

        self::assertSame('de', $schema['i18n']['locale']);
        self::assertSame('Produkte', $products['label']);
        self::assertSame('Preis', $fields['priceCents']['label']);
        self::assertNotSame('Draft', $status['draft'], 'enum options are translated');
        self::assertNotSame('Reprice', array_column($products['actions'], 'label', 'name')['reprice-products'], 'actions are translated');
        self::assertContains('Katalog', array_column($schema['menu'], 'label'));
    }

    public function testTheLanguagesOnOfferIncludeHebrewRightToLeft(): void
    {
        $locales = array_column($this->schema($this->headersIn('he'))['i18n']['locales'], null, 'code');

        self::assertSame(['de', 'en', 'es', 'fr', 'it', 'nl', 'pt', 'he'], array_keys($locales));
        self::assertSame(['code' => 'he', 'name' => 'עברית', 'dir' => 'rtl'], $locales['he']);
        self::assertSame('ltr', $locales['nl']['dir']);
    }

    public function testWithoutAChoiceTheDefaultAppliesWhateverTheBrowserAsksFor(): void
    {
        $viewer = $this->headersIn(null);

        $german = $this->client->get('/vector/schema', headers: [...$viewer, 'Accept-Language' => 'de-CH, de;q=0.9'])->assertOk();
        self::assertSame('en', $german->json()['i18n']['locale'], 'the browser asks for German; the administrator\'s default is English');
        self::assertSame('Products', $german->json()['resources']['products']['label']);

        // the ETag covers the language
        $french = $this->client->get('/vector/schema', headers: $this->headersIn('fr'))->assertOk();
        self::assertSame('fr', $french->json()['i18n']['locale']);
        self::assertNotSame($german->getHeaderLine('ETag'), $french->getHeaderLine('ETag'));
    }

    public function testALanguageSwitchedOffGivesWayToTheDefaultNotTheBrowsersNextOne(): void
    {
        // English, the browser's first language, then Dutch: switching English off must not land on Dutch
        $viewer = $this->headersIn('en');
        $this->client->put('/vector/settings/languages', body: ['en' => false, 'default' => 'he'], headers: $this->realAdminHeaders())->assertOk();

        try {
            $schema = $this->client->get('/vector/schema', headers: [...$viewer, 'Accept-Language' => 'en-US, en;q=0.9, nl;q=0.8'])->assertOk()->json();
            self::assertSame('he', $schema['i18n']['locale'], 'their choice is off: the administrator\'s default');
            self::assertNotContains('en', array_column($schema['i18n']['locales'], 'code'));

            // and a language switched off cannot be chosen again
            $this->client->put('/vector/preferences', body: ['locale' => 'en'], headers: $viewer)->assertValidationError('locale');
        } finally {
            $this->client->put('/vector/settings/languages', body: [], headers: $this->realAdminHeaders())->assertOk();
        }

        self::assertSame('en', $this->schema($viewer)['i18n']['locale'], 'switched on again, their choice returns');
    }

    public function testTheDefaultLanguageIsEnglishUntilTheAdministratorChangesIt(): void
    {
        $group = $this->client->get('/vector/settings/languages', headers: $this->realAdminHeaders())->assertOk()->json()['data'];
        $fields = array_column($group['fields'], null, 'name');

        self::assertSame('en', $group['values']['default'], 'the settings page shows English, not an empty choice');
        self::assertTrue($fields['default']['hasDefault']);
        self::assertSame(['de', 'en', 'es', 'fr', 'it', 'nl', 'pt', 'he'], array_keys(array_filter($group['values'], static fn (mixed $on): bool => $on === true)));

        // the default is always a language that is switched on — so at least one is
        $this->client->put('/vector/settings/languages', body: ['en' => false], headers: $this->realAdminHeaders())
            ->assertValidationError('default');
        $this->client->put('/vector/settings/languages', body: ['de' => false, 'default' => 'de'], headers: $this->realAdminHeaders())
            ->assertValidationError('default');
        self::assertTrue($this->client->get('/vector/settings/languages', headers: $this->realAdminHeaders())->assertOk()->json()['data']['values']['en'], 'a refused save changes nothing');

        $this->client->put('/vector/settings/languages', body: ['default' => 'fr'], headers: $this->realAdminHeaders())->assertOk();

        try {
            self::assertSame('fr', $this->schema($this->headersIn(null))['i18n']['locale']);
        } finally {
            $this->client->put('/vector/settings/languages', body: [], headers: $this->realAdminHeaders())->assertOk();
        }
    }

    public function testOnlyALanguageOnOfferCanBeChosen(): void
    {
        $viewer = $this->headersIn(null);

        $this->client->put('/vector/preferences', body: ['locale' => 'ja'], headers: $viewer)->assertValidationError('locale');
        $this->client->put('/vector/preferences', body: ['locale' => 42], headers: $viewer)->assertValidationError('locale');
        $this->client->put('/vector/preferences', body: ['locale' => 'de'], headers: $viewer)->assertOk();
    }

    public function testSettingsAndDashboardsAreTranslatedToo(): void
    {
        $viewer = $this->headersIn('es');
        $groups = array_column($this->client->get('/vector/settings', headers: $viewer)->assertOk()->json()['data'], null, 'name');
        self::assertNotSame('Branding', $groups['branding']['label'], 'Vector\'s own labels ship translated');
        self::assertNotSame('Languages', $groups['languages']['label']);
        self::assertSame('Deutsch', array_column($groups['languages']['fields'], 'label', 'name')['de'], 'a language keeps its own name');
        self::assertSame('Vector', $groups['branding']['values']['title'], 'values are data, never translated');

        $overview = $this->client->get('/vector/dashboards/main', headers: $viewer)->assertOk()->json()['data'];
        self::assertNotSame('Overview', $overview['label']);
    }

    public function testTheCompiledRegistryKeepsTheDeclaredLabels(): void
    {
        $this->schema($this->headersIn('de'));
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);

        self::assertSame('Products', $registry->resource('products')->label());
    }

    public function testAMissingTranslationKeepsTheDeclaredText(): void
    {
        $labels = new Labels(new MessagesTranslator(['de' => ['Products' => 'Produkte']]));

        $translated = $labels->document(['label' => 'Products', 'help' => 'Untranslated help', 'values' => ['label' => 'Products'], 'ranges' => ['7d' => 'Products']], 'de-AT');

        self::assertSame(['label' => 'Produkte', 'help' => 'Untranslated help', 'values' => ['label' => 'Products'], 'ranges' => ['7d' => 'Produkte']], $translated);
        self::assertSame('Products', $labels->text('Products', 'fr'));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function schema(array $headers): array
    {
        $schema = $this->client->get('/vector/schema', headers: $headers)->assertOk()->json();
        self::assertIsArray($schema);

        return $schema;
    }
}
