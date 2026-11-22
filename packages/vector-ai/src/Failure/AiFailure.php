<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Failure;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;

/**
 * A failed AI call as the admin is told it: a category and a status, with
 * a message that names neither the prompt nor the provider's answer nor a
 * key. The provider's exception stays the previous one, for code that
 * needs it, and is never rendered.
 */
final class AiFailure extends \RuntimeException implements HttpStatusExceptionInterface
{
    public function __construct(
        public readonly AiFailureCategory $category,
        ?string $message = null,
        public readonly ?int $providerStatus = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message ?? $category->message(), 0, $previous);
    }

    /** No provider or model is configured for what was asked. */
    public static function unconfigured(string $why): self
    {
        return new self(AiFailureCategory::Unavailable, $why);
    }

    #[\Override]
    public function httpStatus(): int
    {
        return $this->category->status();
    }
}
