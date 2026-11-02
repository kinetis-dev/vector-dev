<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues;

use App\Tests\Vector\SignsInThroughTheForm;
use App\Tests\Vector\UiFixtures;
use App\Tests\Vector\VectorTestCase;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Ui\ExtensionModules;
use Kinetis\Vector\Ui\VectorUi;

/**
 * kinetis/vector-queues ships its admin UI as the module its
 * composer.json declares (`extra.kinetis.vector.extensions`): the demo,
 * which installs the package, lists it in the hosted shell and serves
 * its exact bytes at the content-hashed URL, as JavaScript. Only the
 * shell document is a test double; the modules are the demo's own,
 * discovered from Composer's installed.json at boot.
 */
final class QueuesUiModuleTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    private string $ui;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->ui = UiFixtures::tempDir('queues-ui-module');
        file_put_contents($this->ui . '/index.html', '<!doctype html><html><head></head><body></body></html>');
        $app->instance(VectorUi::class, VectorUi::fromConfig(new Config(['VECTOR_UI_DIR' => $this->ui, 'VECTOR_UI_EXTENSIONS' => '']), $this->projectRoot()));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        UiFixtures::remove($this->ui);
    }

    /**
     * The installed package's module, and the URL its bytes are published at.
     *
     * @return array{string, string}
     */
    private function module(): array
    {
        $contents = file_get_contents($this->projectRoot() . '/vendor/kinetis/vector-queues/resources/ui/queues.js');
        self::assertIsString($contents);

        return [$contents, ExtensionModules::PATH . '/kinetis/vector-queues/' . ExtensionModules::hash($contents) . '/queues.js'];
    }

    public function testTheShellListsTheQueuesModule(): void
    {
        [, $url] = $this->module();
        [$cookie] = $this->signIn();
        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie])->assertOk();
        $config = preg_match('~window\.__VECTOR__ = (\{.*?\});</script>~', $shell->body(), $m) === 1 ? json_decode($m[1], true) : null;

        self::assertIsArray($config, 'the shell carries its configuration');
        self::assertIsArray($config['extensions']);
        self::assertContains($url, $config['extensions']);
    }

    public function testTheQueuesModuleIsServedAsJavaScriptAtItsHashedUrl(): void
    {
        [$contents, $url] = $this->module();
        $response = $this->client->get($url)->assertOk();

        self::assertSame($contents, $response->body());
        $response->assertHeader('Content-Type', 'text/javascript; charset=utf-8');
        $response->assertHeader('Cache-Control', 'public, max-age=31536000, immutable');
        self::assertStringContainsString("definePage('queues'", $response->body());

        // another hash is not the current bytes under an old name
        $this->client->get(ExtensionModules::PATH . '/kinetis/vector-queues/0000000000000000/queues.js')->assertStatus(404);
    }
}
