<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Testing;

use NeuronAI\Testing\RequestRecord;

/**
 * Every call the `fake` providers of a ScriptedReplies answered, in
 * order: the messages, the system prompt and, for a structured call, its
 * schema. A test creates one, binds the replies holding it, and reads it
 * after the request; it is never bound outside a test.
 */
final class ScriptedCalls
{
    /** @var list<RequestRecord> */
    private array $records = [];

    public function add(RequestRecord $record): void
    {
        $this->records[] = $record;
    }

    /** @return list<RequestRecord> */
    public function all(): array
    {
        return $this->records;
    }
}
