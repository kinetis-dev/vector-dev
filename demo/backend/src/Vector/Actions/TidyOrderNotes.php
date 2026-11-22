<?php

declare(strict_types=1);

namespace App\Vector\Actions;

use Kinetis\VectorAi\Actions\ProposeRewrites;
use Kinetis\VectorAi\Writing\WritingOperation;

/**
 * The demo's bulk AI rewrite: spelling and grammar of the selected
 * orders' notes, proposed as a CSV to review and import (kinetis/vector-ai).
 */
final class TidyOrderNotes extends ProposeRewrites
{
    #[\Override]
    public function field(): string
    {
        return 'notes';
    }

    #[\Override]
    public function operation(): WritingOperation
    {
        return WritingOperation::Grammar;
    }
}
