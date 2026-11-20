<?php

declare(strict_types=1);

namespace App\Vector\Ai;

use Kinetis\VectorAi\Testing\ScriptedReplies;

/**
 * What the demo's `fake` AI provider answers (VECTOR_AI_<NAME>_TYPE=fake,
 * docs/ai.md), so plain-language filters can be tried — and the
 * end-to-end suite can drive them — without a model: each sentence below,
 * typed on the orders list, gets the filter a model would answer. Any
 * other sentence gets no filter. Unused while no fake provider is
 * configured.
 */
final class DemoAiReplies
{
    /** The sentence the end-to-end suite types on the orders list. */
    public const string PAID_OVER_500 = 'paid orders over 500';

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
        ]);
    }
}
