<?php

declare(strict_types=1);

namespace App\Vector\Ai;

use Kinetis\VectorAi\Testing\ScriptedReplies;

/**
 * What the demo's `fake` AI provider answers (VECTOR_AI_<NAME>_TYPE=fake,
 * docs/ai.md), so plain-language filters and the assistant can be tried —
 * and the end-to-end suite can drive them — without a model: the filter
 * sentence below, typed on the orders list, gets the filter a model would
 * answer; the assistant question reads the low-stock products with
 * vector_query, then answers naming a product as `products#id`. Any other
 * sentence gets no filter, or "No reply is scripted for this message.".
 * Unused while no fake provider is configured.
 */
final class DemoAiReplies
{
    /** The sentence the end-to-end suite types on the orders list. */
    public const string PAID_OVER_500 = 'paid orders over 500';

    /** The question the end-to-end suite asks the assistant. */
    public const string LOW_STOCK = 'Which products are low on stock?';

    /** What the assistant answers it, once its query has run. */
    public const string LOW_STOCK_ANSWER = 'I listed the active products with 10 or fewer in stock, lowest first. Start with products#1, then check the rest of the list.';

    public static function replies(): ScriptedReplies
    {
        return ScriptedReplies::of([
            self::PAID_OVER_500 => json_encode([
                'conditions' => [
                    ['field' => 'status', 'op' => 'eq', 'value' => 'paid'],
                    ['field' => 'totalCents', 'op' => 'gt', 'value' => '50000'],
                ],
                'anyOf' => [],
                'q' => null,
                'explanation' => 'Paid orders with a total above 500.00.',
            ], JSON_THROW_ON_ERROR),
            self::LOW_STOCK => [
                ['toolCalls' => [['name' => 'vector_query', 'arguments' => [
                    'resource' => 'products',
                    'filter' => ['status' => 'active', 'stock' => ['lte' => '10']],
                    'sort' => 'stock',
                    'perPage' => 10,
                ]]]],
                ['text' => self::LOW_STOCK_ANSWER],
            ],
        ]);
    }
}
