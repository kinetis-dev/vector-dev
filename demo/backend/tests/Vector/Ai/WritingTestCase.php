<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Testing\TestResponse;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\VectorAi\Writing\WritingAssistant;

/**
 * Writing help against the `fake` provider (FiltersTestCase's setup),
 * over the demo's registry with two changes a test needs and the demo
 * does not have: products' SKU is read-only, and tags' name opts out of
 * AI (`ai.send` false). scriptWriting() binds the answer to a text.
 */
abstract class WritingTestCase extends FiltersTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach ($data['resources']['products']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'sku') {
                $data['resources']['products']['fields'][$i]['readonly'] = true;
            }
        }

        foreach ($data['resources']['tags']['fields'] as $i => $field) {
            if (is_array($field) && ($field['name'] ?? null) === 'name') {
                $data['resources']['tags']['fields'][$i]['meta'] = ['ai.send' => false];
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    /** @return list<array<string, mixed>> this actor's usage rows, oldest first */
    protected function usageRows(int $actor): array
    {
        return $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . str_pad((string) $actor, 2, '0', STR_PAD_LEFT))->orderBy('id', 'ASC')->get();
    }

    /**
     * What the fake answers writing help about each text: the text => the
     * answer; and the text => what it throws instead, for $failures.
     *
     * @param array<string, string> $answers
     * @param array<string, \Throwable> $failures
     */
    protected function scriptWriting(array $answers, array $failures = []): void
    {
        $keyed = [];
        $thrown = [];

        foreach ($answers as $text => $answer) {
            $keyed[WritingAssistant::content((string) $text)] = $answer;
        }

        foreach ($failures as $text => $failure) {
            $thrown[WritingAssistant::content((string) $text)] = $failure;
        }

        $this->script($keyed, $thrown);
    }

    /** @param array<string, mixed> $body */
    protected function write(string $resource, string $field, array $body, int $actor = 1): TestResponse
    {
        return $this->client->post("/vector/ai/writing/{$resource}/{$field}", $body, $this->aiHeaders($actor));
    }

    /** The id of a seeded order. */
    protected function orderId(): string
    {
        $order = $this->shop()->table('orders')->select('id')->orderBy('id', 'ASC')->first();
        self::assertIsArray($order);

        return (string) $order['id'];
    }

    /** The id of a seeded, not deleted, product. */
    protected function productId(): string
    {
        $product = $this->shop()->table('products')->select('id')->where('deleted_at', '=', null)->orderBy('id', 'ASC')->first();
        self::assertIsArray($product);

        return (string) $product['id'];
    }
}
