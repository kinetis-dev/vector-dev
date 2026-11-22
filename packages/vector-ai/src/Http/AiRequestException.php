<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Http;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;

/** A request the AI endpoints refuse, with the status it is answered with and a message safe to show. */
final class AiRequestException extends \RuntimeException implements HttpStatusExceptionInterface
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message);
    }

    #[\Override]
    public function httpStatus(): int
    {
        return $this->status;
    }
}
