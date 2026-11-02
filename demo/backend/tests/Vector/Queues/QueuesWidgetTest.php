<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\VectorTestCase;
use Kinetis\Vector\Queues\Widgets\QueuesWidget;

/**
 * The queue dashboard's card on the demo's main dashboard: its
 * descriptor names the extension type queues.js renders, and its own
 * answer carries nothing but what Vector writes into every answer, since
 * the card reads GET /vector/queues itself.
 */
final class QueuesWidgetTest extends VectorTestCase
{
    public function testTheMainDashboardPlacesTheQueuesCard(): void
    {
        $page = $this->client->get('/vector/dashboards/main', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        $cards = array_column($page['data']['widgets'], null, 'name');

        self::assertSame(
            ['name' => 'queues', 'label' => 'Queues', 'type' => QueuesWidget::TYPE, 'icon' => 'mdi-tray-full', 'ranges' => [], 'width' => 4, 'cacheSeconds' => 0, 'cacheShared' => false],
            $cards['queues'] ?? null,
        );
    }

    /**
     * The demo's translations no longer carry "Queues": the package's own
     * resources/translations, declared in its composer.json, do.
     */
    public function testTheCardsLabelIsTranslatedByThePackageItself(): void
    {
        foreach (['de' => 'Warteschlangen', 'he' => 'תורים', 'pt-BR' => 'Filas'] as $locale => $label) {
            $page = $this->client->get('/vector/dashboards/main', headers: $this->headersIn($locale))->assertOk()->json();
            self::assertIsArray($page);

            self::assertSame($label, array_column($page['data']['widgets'], 'label', 'name')['queues'] ?? null, $locale);
        }
    }

    public function testTheCardsOwnAnswerCarriesNoQueueData(): void
    {
        $this->client->get('/vector/dashboards/main/widgets/queues', headers: $this->adminHeaders())->assertOk()
            ->assertJsonPath('data', ['type' => QueuesWidget::TYPE, 'range' => 'all', 'cached' => false]);
    }
}
