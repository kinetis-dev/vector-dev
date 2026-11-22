<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Config;

/** How a configured provider is spoken to: VECTOR_AI_<NAME>_TYPE. */
enum ProviderType: string
{
    case OpenAi = 'openai';
    case Anthropic = 'anthropic';
    case OpenAiLike = 'openai-like';
    case Ollama = 'ollama';
    case Bedrock = 'bedrock';
    case Fake = 'fake';

    /** The base URL Neuron's own provider talks to, when the type has a fixed one. */
    public function defaultBaseUrl(): ?string
    {
        return match ($this) {
            self::OpenAi => 'https://api.openai.com/v1',
            self::Anthropic => 'https://api.anthropic.com/v1',
            default => null,
        };
    }

    /** Whether the type is reached over HTTP through a NeuronHttpClient (Bedrock signs its own, fake sends nothing). */
    public function overHttp(): bool
    {
        return !in_array($this, [self::Bedrock, self::Fake], true);
    }

    /** Whether a call needs an API key: from the environment, or sealed by an administrator. */
    public function needsKey(): bool
    {
        return in_array($this, [self::OpenAi, self::Anthropic], true);
    }

    /** Whether an API key may be given at all (an OpenAI-compatible server may want one). */
    public function takesKey(): bool
    {
        return in_array($this, [self::OpenAi, self::Anthropic, self::OpenAiLike], true);
    }

    /** Whether plain http:// is allowed: only for servers an operator runs, such as a local Ollama. */
    public function allowsPlainHttp(): bool
    {
        return in_array($this, [self::Ollama, self::OpenAiLike], true);
    }
}
