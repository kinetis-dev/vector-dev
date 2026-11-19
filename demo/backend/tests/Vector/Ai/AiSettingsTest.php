<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Validation\Violation;
use Kinetis\VectorAi\Settings\AiSettings;

/**
 * The `ai` settings group: a provider must be a configured one and a
 * model on its allow-list; keys are never settings.
 */
final class AiSettingsTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_PROVIDERS' => 'test-openai',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_TEST_OPENAI_MODELS' => 'gpt-small,gpt-large',
        ];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testValidateRefusesAnUnknownProviderAndAModelOffTheList(): void
    {
        $group = $this->app->get(AiSettings::class);
        self::assertInstanceOf(AiSettings::class, $group);

        $paths = static fn (array $violations): array => array_map(static fn (Violation $v): string => implode('.', $v->path), $violations);

        self::assertSame(['filtersProvider'], $paths($group->validate(['filtersProvider' => 'gemini'])));
        self::assertSame(['writingModel'], $paths($group->validate(['writingProvider' => 'test-openai', 'writingModel' => 'gpt-huge'])));
        self::assertSame([], $group->validate(['assistantProvider' => 'test-openai', 'assistantModel' => 'gpt-large']));
        self::assertSame([], $group->validate([]), 'nothing chosen is valid: the only provider is used');
    }

    public function testWithNoProviderChosenTheModelIsCheckedAgainstTheOnlyOne(): void
    {
        $group = $this->app->get(AiSettings::class);
        self::assertInstanceOf(AiSettings::class, $group);

        $violations = $group->validate(['filtersModel' => 'gpt-huge']);
        self::assertSame(['filtersModel'], array_map(static fn (Violation $v): string => implode('.', $v->path), $violations));
        self::assertSame('model_not_allowed', $violations[0]->code);
        self::assertSame([], $group->validate(['writingModel' => 'gpt-large']));

        $this->client->request('PUT', '/vector/settings/ai', body: ['assistantModel' => 'gpt-huge'], headers: $this->adminHeaders())
            ->assertValidationError('assistantModel');
    }

    public function testTheEndpointRefusesAnUnknownProvider(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersProvider' => 'gemini'], headers: $this->adminHeaders())
            ->assertValidationError('filtersProvider');
        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersProvider' => 'test-openai', 'filtersModel' => 'gpt-small', 'maxToolCallsPerRun' => 21], headers: $this->adminHeaders())
            ->assertValidationError('maxToolCallsPerRun');
        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersProvider' => 'test-openai', 'filtersModel' => 'gpt-small'], headers: $this->adminHeaders())
            ->assertOk();
    }

    public function testTheGroupHoldsNoKey(): void
    {
        $settings = $this->client->get('/vector/settings/ai', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($settings);
        $names = array_column($settings['data']['fields'] ?? $settings['fields'] ?? [], 'name');

        self::assertContains('dataDefault', $names);
        self::assertSame([], array_values(array_filter($names, static fn (string $n): bool => stripos($n, 'key') !== false)));
        self::assertSame(200000, ($settings['data']['values'] ?? $settings['values'] ?? [])['dailyTokenBudgetPerUser'] ?? null);
    }
}
