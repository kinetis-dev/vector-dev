<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Settings;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Constraints\GreaterThanOrEqual;
use Kinetis\Validation\Constraints\LessThanOrEqual;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Fields\Boolean;
use Kinetis\Vector\Fields\Number;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Feature;
use Kinetis\Vector\Fields\Select;

/**
 * The `ai` settings group: which features are on, which configured
 * provider and model each calls, and the limits a run keeps. The
 * providers themselves, and their keys, are the operator's
 * (VECTOR_AI_*) and the credentials endpoints'; a provider key is never
 * a setting, because settings are stored and served in plain text.
 *
 * The definition is compile-time data (fields() needs no dependency);
 * the request container builds the group with its policy and the
 * configuration for canManage() and validate().
 */
#[Settings]
final class AiSettings extends SettingsGroup
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly AiAccessInterface $access,
    ) {}

    #[\Override]
    public function name(): string
    {
        return 'ai';
    }

    #[\Override]
    public function label(): string
    {
        return 'AI assistant';
    }

    #[\Override]
    public function icon(): string
    {
        return 'mdi-creation-outline';
    }

    #[\Override]
    public function fields(): array
    {
        return [
            Boolean::make('filtersEnabled', 'Plain-language filters')->default(true),
            Boolean::make('assistantEnabled', 'Assistant')->default(true),
            Boolean::make('assistantWrites', 'Assistant may propose changes')->default(false)
                ->help('Every change it proposes still waits for a person to approve it.'),
            Boolean::make('writingEnabled', 'Writing help')->default(true),
            Text::make('filtersProvider', 'Filters provider')->max(40)->help('A provider VECTOR_AI_PROVIDERS lists; empty uses the only one.'),
            Text::make('filtersModel', 'Filters model')->max(200),
            Text::make('assistantProvider', 'Assistant provider')->max(40),
            Text::make('assistantModel', 'Assistant model')->max(200),
            Text::make('writingProvider', 'Writing help provider')->max(40),
            Text::make('writingModel', 'Writing help model')->max(200),
            Number::make('dailyTokenBudgetPerUser', 'Daily token budget per user')->required()->default(200000)
                ->rule(GreaterThanOrEqual::class, 0)
                ->help('Input and output tokens each person may spend a day (UTC); 0 turns AI off for everyone.'),
            Number::make('maxToolCallsPerRun', 'Tool calls per assistant run')->required()->default(8)
                ->rule(GreaterThanOrEqual::class, 1)->rule(LessThanOrEqual::class, 20),
            Number::make('approvalMinutes', 'Minutes a proposed change waits for approval')->required()->default(15)
                ->rule(GreaterThanOrEqual::class, 1)->rule(LessThanOrEqual::class, 120),
            Select::make('dataDefault', 'Record data sent to the model')->of(DataDefault::class)->required()->default(DataDefault::Send->value)
                ->labels(['send' => 'Every field, except those marked not to send', 'allowlist' => 'Only fields marked to send'])
                ->help('A resource or field opts out with meta(\'ai.send\', false), or in with meta(\'ai.send\', true).'),
        ];
    }

    #[\Override]
    public function canManage(CurrentUserInterface $user): bool
    {
        return $this->access->canConfigure($user);
    }

    /**
     * Each provider a configured one, and each model on that provider's
     * allow-list.
     *
     * @param array<string, mixed> $values
     * @return list<Violation>
     */
    #[\Override]
    public function validate(array $values): array
    {
        $violations = [];

        foreach (Feature::cases() as $feature) {
            if ($feature === Feature::AssistantWrites) {
                continue; // the assistant's provider and model
            }

            $violation = $this->violation($feature, $values);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * What is wrong with $feature's provider or, on a known provider, its
     * model; null when nothing is.
     *
     * @param array<string, mixed> $values
     */
    private function violation(Feature $feature, array $values): ?Violation
    {
        $providerField = $feature->providerSetting();
        $modelField = $feature->modelSetting();
        $name = trim((string) ($values[$providerField] ?? ''));
        $model = trim((string) ($values[$modelField] ?? ''));

        if ($name === '') {
            // the provider the resolver falls back to, when there is one
            $provider = $this->config->soleProvider();

            if ($provider === null) {
                return null;
            }

            $name = $provider->name;
        } else {
            $provider = $this->config->provider($name);
        }

        if ($provider === null) {
            $known = array_keys($this->config->providers);

            return new Violation([$providerField], 'unknown_provider', $known === []
                ? 'No AI provider is configured; the operator lists them in VECTOR_AI_PROVIDERS.'
                : "is not a configured provider: " . implode(', ', $known) . '.');
        }

        if ($model !== '' && !$provider->allows($model)) {
            return new Violation([$modelField], 'model_not_allowed', "is not a model {$name} allows: " . implode(', ', $provider->models) . '.');
        }

        return null;
    }
}
