<?php

declare(strict_types=1);

namespace Kinetis\VectorAi;

/** What an AI call is for: each has its own switch, provider and model in the `ai` settings group. */
enum Feature: string
{
    /** Plain-language filters on a list. */
    case Filters = 'filters';

    /** The in-admin chat assistant, reading. */
    case Assistant = 'assistant';

    /** The assistant proposing writes, which a person approves. */
    case AssistantWrites = 'assistant-writes';

    /** Writing help in text, Markdown and rich-text inputs. */
    case Writing = 'writing';

    /** The settings field naming the provider this feature calls; writes share the assistant's. */
    public function providerSetting(): string
    {
        return match ($this) {
            self::Filters => 'filtersProvider',
            self::Assistant, self::AssistantWrites => 'assistantProvider',
            self::Writing => 'writingProvider',
        };
    }

    /** The settings field naming the model this feature calls. */
    public function modelSetting(): string
    {
        return match ($this) {
            self::Filters => 'filtersModel',
            self::Assistant, self::AssistantWrites => 'assistantModel',
            self::Writing => 'writingModel',
        };
    }

    /**
     * How long one provider call of this feature may take, in seconds, when
     * it is shorter than VECTOR_AI_TIMEOUT; null leaves the transport's own.
     * A filter is asked for while someone waits on a list.
     */
    public function timeout(): ?float
    {
        return match ($this) {
            self::Filters => 30.0,
            self::Assistant, self::AssistantWrites, self::Writing => null,
        };
    }

    /** The settings switch that turns this feature on. */
    public function enabledSetting(): string
    {
        return match ($this) {
            self::Filters => 'filtersEnabled',
            self::Assistant => 'assistantEnabled',
            self::AssistantWrites => 'assistantWrites',
            self::Writing => 'writingEnabled',
        };
    }
}
