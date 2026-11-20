<?php

declare(strict_types=1);

namespace App\Vector\Ai;

use Kinetis\VectorAi\Testing\ScriptedReplies;
use Kinetis\VectorAi\Writing\WritingAssistant;

/**
 * What the demo's `fake` AI provider answers (VECTOR_AI_<NAME>_TYPE=fake,
 * docs/ai.md), so plain-language filters and the assistant can be tried —
 * and the end-to-end suite can drive them — without a model: the filter
 * sentence below, typed on the orders list, gets the filter a model would
 * answer; the assistant question reads the low-stock products with
 * vector_query, then answers naming a product as `products#id`; and
 * writing help shortens the two texts below, an order's notes and a
 * product description's paragraph. Any other sentence gets no filter,
 * or "No reply is scripted for this message.".
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

    /** The order notes the end-to-end suite asks writing help to shorten. */
    public const string LONG_NOTES = 'Please make sure to call the customer before the delivery, because the customer has asked us to call ahead before the parcel is delivered.';

    /** What writing help shortens them to. */
    public const string SHORT_NOTES = 'Call the customer before delivery.';

    /** The description paragraph the end-to-end suite selects and asks writing help to shorten. */
    public const string LONG_DESCRIPTION = 'This lamp gives a warm and pleasant light that is very suitable for reading in the evening and for other quiet activities at home.';

    /** What writing help shortens it to. */
    public const string SHORT_DESCRIPTION = 'A warm reading lamp for quiet evenings.';

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
            // writing help keys by the delimited text, whatever the operation
            WritingAssistant::content(self::LONG_NOTES) => self::SHORT_NOTES,
            WritingAssistant::content(self::LONG_DESCRIPTION) => self::SHORT_DESCRIPTION,
        ]);
    }
}
