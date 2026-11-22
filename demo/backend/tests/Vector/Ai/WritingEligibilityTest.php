<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * Which text fields writing help is on for (WritingGuard::helps()), as
 * POST /vector/ai/writing/{resource}/{field} decides it and
 * `capabilities.ai.noWriting` tells the admin: products' SKU as the demo
 * defines it (a writable unique string, an identifier) is refused;
 * tags' name, a unique string that opts in with `ai.writing` true, is
 * helped; products' name, opted out with `ai.writing` false, is refused
 * yet still sent to the model (`ai.send` true), so excludedFields does
 * not name it.
 */
final class WritingEligibilityTest extends WritingTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ($data['resources']['products']['fields'] as $i => $field) {
            // the SKU as the demo has it: writable, unique, no override
            if (is_array($field) && ($field['name'] ?? null) === 'sku') {
                $data['resources']['products']['fields'][$i]['readonly'] = false;
                unset($data['resources']['products']['fields'][$i]['meta']);
            }

            if (is_array($field) && ($field['name'] ?? null) === 'name') {
                $data['resources']['products']['fields'][$i]['meta'] = ['ai.send' => true, 'ai.writing' => false];
            }
        }

        foreach ($data['resources']['tags']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'name') {
                $data['resources']['tags']['fields'][$i]['meta'] = ['ai.writing' => true];
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testAUniqueStringIsRefusedBeforeAnyCall(): void
    {
        $this->scriptWriting(['SKU-0001' => 'SKU-0002']);

        $this->write('products', 'sku', ['operation' => 'rewrite', 'text' => 'SKU-0001', 'recordId' => $this->productId()])
            ->assertStatus(403)
            ->assertJsonPath('error', 'Writing help is off for this field of Products.');
        $this->write('products', 'sku', ['operation' => 'rewrite', 'text' => 'SKU-0001'])->assertStatus(403);

        self::assertSame([], $this->calls());
        self::assertSame([], $this->usageRows(1));
    }

    public function testAUniqueStringThatOptsInIsHelped(): void
    {
        $this->scriptWriting(['Garden tools' => 'Tools for the garden']);
        $tag = (int) $this->shop()->table('tags')->orderBy('id', 'ASC')->value('id');

        $this->write('tags', 'name', ['operation' => 'rewrite', 'text' => 'Garden tools', 'recordId' => (string) $tag])
            ->assertOk()
            ->assertJsonPath('text', 'Tools for the garden');

        self::assertCount(1, $this->calls());
    }

    public function testATextFieldThatOptsOutIsRefusedYetStillSentToTheModel(): void
    {
        $this->scriptWriting(['Spade' => 'Garden spade']);

        $this->write('products', 'name', ['operation' => 'rewrite', 'text' => 'Spade', 'recordId' => $this->productId()])
            ->assertStatus(403)
            ->assertJsonPath('error', 'Writing help is off for this field of Products.');
        self::assertSame([], $this->calls());

        $ai = $this->client->get('/vector/schema', headers: $this->aiHeaders())->assertOk()->json()['capabilities']['ai'] ?? null;
        self::assertIsArray($ai);
        self::assertSame(['imageKey'], $ai['excludedFields']['products'] ?? null, 'writing help off keeps nothing from the model');
        self::assertSame(['sku', 'name'], $ai['noWriting']['products'] ?? null);
        self::assertArrayNotHasKey('tags', $ai['noWriting'], 'a unique string that opts in is offered');
        self::assertArrayNotHasKey('orders', $ai['noWriting']);
    }
}
