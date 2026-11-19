<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Failure;

/** Why an AI call failed, in the few words a person and a log line need; never the provider's own text. */
enum AiFailureCategory: string
{
    case Timeout = 'timeout';
    case TooLarge = 'too_large';
    case Refused = 'refused';
    case Unavailable = 'unavailable';
    case InvalidRequest = 'invalid_request';

    /** The HTTP status an AI endpoint answers with. */
    public function status(): int
    {
        return match ($this) {
            self::Timeout => 504,
            self::TooLarge => 502,
            self::Refused => 502,
            self::Unavailable => 503,
            self::InvalidRequest => 422,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::Timeout => 'The AI provider did not answer in time.',
            self::TooLarge => 'The AI provider\'s answer was larger than allowed.',
            self::Refused => 'The AI provider refused the request.',
            self::Unavailable => 'The AI provider is not available.',
            self::InvalidRequest => 'The AI provider could not use the request.',
        };
    }
}
