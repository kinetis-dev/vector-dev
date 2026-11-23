<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Validation\Violation;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Settings\AiSettings;
use PHPUnit\Framework\TestCase;

/**
 * AiSettings::validate() on a configuration given rather than read,
 * database-free: each feature's violation by path, code and message, in
 * the order of the features, for no provider, several and one.
 */
final class AiSettingsValidateTest extends TestCase
{
    public function testWithNoProviderConfiguredAChosenOneIsRefusedAndAnEmptyOneIsNot(): void
    {
        self::assertSame([
            [['filtersProvider'], 'unknown_provider', 'No AI provider is configured; the operator lists them in VECTOR_AI_PROVIDERS.'],
            [['writingProvider'], 'unknown_provider', 'No AI provider is configured; the operator lists them in VECTOR_AI_PROVIDERS.'],
        ], $this->validate([], ['filtersProvider' => 'openai', 'assistantModel' => 'gpt-a', 'writingProvider' => ' x ']));
    }

    public function testWithSeveralProvidersEachFeatureIsCheckedInOrder(): void
    {
        $config = [
            'VECTOR_AI_PROVIDERS' => 'openai,other',
            'VECTOR_AI_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_OPENAI_KEY' => 'sk-test',
            'VECTOR_AI_OPENAI_MODELS' => 'gpt-a, gpt-b',
            'VECTOR_AI_OTHER_TYPE' => 'openai',
            'VECTOR_AI_OTHER_KEY' => 'sk-test',
            'VECTOR_AI_OTHER_MODELS' => 'o-1',
        ];

        self::assertSame([
            [['filtersProvider'], 'unknown_provider', 'is not a configured provider: openai, other.'],
            [['assistantModel'], 'model_not_allowed', 'is not a model openai allows: gpt-a, gpt-b.'],
        ], $this->validate($config, [
            'filtersProvider' => 'gemini',
            'filtersModel' => 'never-checked',
            'assistantProvider' => ' openai ',
            'assistantModel' => 'gpt-c',
            // no provider chosen and no sole one: nothing to check the model against
            'writingModel' => 'anything',
        ]));
        self::assertSame([], $this->validate($config, ['assistantProvider' => 'other', 'assistantModel' => 'o-1', 'writingProvider' => 'openai']));
    }

    public function testWithOneProviderAnEmptyChoiceIsChecked(): void
    {
        $config = [
            'VECTOR_AI_PROVIDERS' => 'openai',
            'VECTOR_AI_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_OPENAI_KEY' => 'sk-test',
            'VECTOR_AI_OPENAI_MODELS' => 'gpt-a',
        ];

        self::assertSame([
            [['filtersModel'], 'model_not_allowed', 'is not a model openai allows: gpt-a.'],
            [['writingModel'], 'model_not_allowed', 'is not a model openai allows: gpt-a.'],
        ], $this->validate($config, ['filtersModel' => 'gpt-z', 'assistantModel' => ' gpt-a ', 'writingModel' => 'gpt-y']));
    }

    /**
     * @param array<string, string> $config
     * @param array<string, mixed> $values
     * @return list<array{list<string|int>, string, string}>
     */
    private function validate(array $config, array $values): array
    {
        $settings = new AiSettings(AiConfig::fromConfig(new Config($config)), self::createStub(AiAccessInterface::class));

        return array_map(static fn (Violation $v): array => [$v->path, $v->code, $v->message], $settings->validate($values));
    }
}
