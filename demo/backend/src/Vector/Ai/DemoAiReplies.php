<?php

declare(strict_types=1);

namespace App\Vector\Ai;

use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use Kinetis\VectorAi\Writing\WritingAssistant;

/**
 * What the demo's `fake` AI provider answers (VECTOR_AI_<NAME>_TYPE=fake,
 * docs/ai.md), so plain-language filters and the assistant can be tried
 * (and the end-to-end suite can drive them) without a model: the filter
 * sentence below, typed on the orders list, gets the filter a model would
 * answer; the assistant question reads the low-stock products with
 * vector_query, then answers naming a product as `products#id`; with
 * `assistantWrites` on, the two restocking requests read products#9 with
 * vector_get and propose its stock (vector_update), which the admin
 * approves or rejects, and the assistant then acknowledges the decision;
 * and writing help shortens the two texts below, an order's notes and a
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

    /** The product the restocking requests are about. */
    public const string RESTOCKED = '9';

    /** A request the assistant answers with a proposal: products#9's stock to 42. */
    public const string RESTOCK = 'Set the stock of products#9 to 42.';

    /** Another, proposing products#9's stock to 7. */
    public const string RESTOCK_AGAIN = 'Set the stock of products#9 to 7.';

    /** What the assistant answers once it proposed a restock. */
    public const string RESTOCK_ANSWER = 'I proposed the new stock of products#9; it changes once you approve it.';

    /** What the assistant answers once the admin decided on a proposal. */
    public const string DECIDED_ANSWER = 'Noted: your decision on the change I proposed is recorded above.';

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
            self::RESTOCK => self::restock(42),
            self::RESTOCK_AGAIN => self::restock(7),
            // the turn that tells the model how the admin decided ends with these words, whatever the decision
            Assistant::OUTCOME_PROMPT => self::DECIDED_ANSWER,
            // writing help keys by the delimited text, whatever the operation
            WritingAssistant::content(self::LONG_NOTES) => self::SHORT_NOTES,
            WritingAssistant::content(self::LONG_DESCRIPTION) => self::SHORT_DESCRIPTION,
        ]);
    }

    /**
     * Reads products#9, proposes its stock to $stock, then answers.
     *
     * @return list<array{text?: string, toolCalls?: list<array{name: string, arguments?: array<string, mixed>}>}>
     */
    private static function restock(int $stock): array
    {
        return [
            ['toolCalls' => [['name' => 'vector_get', 'arguments' => ['resource' => 'products', 'id' => self::RESTOCKED]]]],
            ['toolCalls' => [['name' => 'vector_update', 'arguments' => ['resource' => 'products', 'id' => self::RESTOCKED, 'values' => ['stock' => $stock]]]]],
            ['text' => self::RESTOCK_ANSWER],
        ];
    }
}
