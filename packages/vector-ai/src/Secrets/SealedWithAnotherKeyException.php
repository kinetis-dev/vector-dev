<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Secrets;

/** A sealed secret whose key is not VECTOR_AI_KEY any more: an administrator has to enter it again. */
final class SealedWithAnotherKeyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sealed with another key; re-enter it.');
    }
}
