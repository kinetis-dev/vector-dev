<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Put;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Registry\SettingsDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\Vector\Store\SettingsStore;
use Kinetis\Vector\Write\FieldInput;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Typed settings groups: the descriptor of every group this user may
 * manage, its current values, and the one write that replaces them.
 */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class SettingsController
{
    public function __construct(
        private VectorRegistry $registry,
        private SettingsStore $store,
        private RequestScope $scope,
        private CurrentUserInterface $user,
        private FieldInput $input,
        private \Kinetis\Vector\Translation\LocaleResolver $locales,
        private \Kinetis\Vector\Translation\Labels $labels,
    ) {}

    /** @return array{data: list<array<string, mixed>>} every manageable group with its values */
    #[Get('/settings')]
    public function index(): array
    {
        $groups = [];
        $locale = $this->locale();

        foreach ($this->registry->settings() as $group) {
            if ($this->manageable($group)) {
                $groups[] = [...$this->labels->document($group->toArray(), $locale), 'values' => $this->store->values($group)];
            }
        }

        return ['data' => $groups];
    }

    /** @return array{data: array<string, mixed>} */
    #[Get('/settings/{group}')]
    public function show(string $group): array
    {
        $descriptor = $this->registry->settingsGroup($group);

        if (!$this->manageable($descriptor)) {
            throw new ForbiddenOperationException('This account may not manage these settings.');
        }

        return ['data' => [...$this->labels->document($descriptor->toArray(), $this->locale()), 'values' => $this->store->values($descriptor)]];
    }

    /**
     * Replaces the group's values with the validated body; an absent
     * field takes its default.
     *
     * @return array{data: array<string, mixed>}
     */
    #[Put('/settings/{group}')]
    public function save(string $group, ServerRequestInterface $request): array
    {
        $descriptor = $this->registry->settingsGroup($group);

        if (!$this->manageable($descriptor)) {
            throw new ForbiddenOperationException('This account may not manage these settings.');
        }

        $raw = (string) $request->getBody();

        try {
            $body = trim($raw) === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw ValidationException::fromViolations([new Violation([], 'type_mismatch', 'The request body must be a JSON object.')]);
        }

        $values = $this->input->validate($descriptor->fields(), $body);
        $stored = [];

        foreach ($values as $name => $value) {
            $stored[$name] = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:s.v\Z'),
                default => $value,
            };
        }

        // a rule across the group's fields, as the group states it
        $group = $this->scope->get($descriptor->groupClass());
        $violations = $group instanceof \Kinetis\Vector\Settings\SettingsGroup ? $group->validate($stored) : [];

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        $this->store->save($descriptor, $stored, (string) $this->user->id());

        return ['data' => [...$this->labels->document($descriptor->toArray(), $this->locale()), 'values' => $this->store->values($descriptor)]];
    }

    private function locale(): string
    {
        return $this->locales->resolve((string) $this->user->id())['locale'];
    }

    private function manageable(SettingsDescriptor $group): bool
    {
        $instance = $this->scope->get($group->groupClass());

        return $instance instanceof SettingsGroup && $instance->canManage($this->user);
    }
}
