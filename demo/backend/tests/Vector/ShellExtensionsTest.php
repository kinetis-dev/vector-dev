<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Ui\VectorUi;

/**
 * The hosted shell lists the deployment's VECTOR_UI_EXTENSIONS exactly
 * as configured, and nothing else: Composer packages declare no UI
 * modules, so the descriptor carries none and Vector serves none.
 */
final class ShellExtensionsTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    /** a repeated entry stays repeated: the shell lists the setting as it is */
    private const string DEPLOYED = '/vector-extensions/stock-level.js,https://cdn.example.test/ext.js,/vector-extensions/stock-level.js';

    private string $root;

    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_UI_EXTENSIONS' => self::DEPLOYED];
    }

    /** A published shell document of its own, so the test never depends on the installed bundle. */
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->root = UiFixtures::tempDir('shell-extensions');
        mkdir($this->root . '/ui');
        file_put_contents($this->root . '/ui/index.html', '<!doctype html><html><head></head><body></body></html>');
        $app->instance(VectorUi::class, VectorUi::fromConfig(new Config(['VECTOR_UI_DIR' => $this->root . '/ui', 'VECTOR_UI_EXTENSIONS' => $config->string('VECTOR_UI_EXTENSIONS', '')]), $this->root));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        UiFixtures::remove($this->root);
    }

    public function testTheShellListsExactlyTheDeploymentsModules(): void
    {
        [$cookie] = $this->signIn();
        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie])->assertOk();
        $config = preg_match('~window\.__VECTOR__ = (\{.*?\});</script>~', $shell->body(), $m) === 1 ? json_decode($m[1], true) : null;
        self::assertIsArray($config, 'the shell carries its configuration');

        self::assertSame([
            '/vector-extensions/stock-level.js',
            'https://cdn.example.test/ext.js',
            '/vector-extensions/stock-level.js',
        ], $config['extensions']);
    }

    public function testTheDescriptorTellsTheUiOnlyItsPinnedVersion(): void
    {
        $descriptor = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();

        self::assertIsArray($descriptor);
        self::assertIsArray($descriptor['ui'] ?? null);
        self::assertSame(['expected'], array_keys($descriptor['ui']));
    }

    public function testVectorServesNoModulesOfItsOwn(): void
    {
        $this->client->get('/vector/extensions/acme/ui-extension/0123456789abcdef/queues.js')->assertStatus(404);
    }
}
