<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;

/**
 * Validates a JSON object against a list of loose compiled fields (an
 * action's dialog, a settings group) the way RecordInput validates a
 * record: every failure collected into the framework's 422 document.
 */
final readonly class FieldInput
{
    public function __construct(private ValueConverter $converter) {}

    /**
     * $payload may hold its JSON objects as stdClass: a `Json` or
     * `KeyValue` input keeps them (a stdClass tree, a list where JSON
     * sent one; a stdClass of strings), and every other input reads them
     * as arrays (JsonObjects::keep()).
     *
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed> field name => converted value, for every field (absent ones as null or their default)
     * @throws ValidationException
     */
    public function validate(array $fields, array $payload): array
    {
        $payload = JsonObjects::keep($fields, $payload);
        $values = [];
        $violations = [];

        foreach ($payload as $name => $raw) {
            if (!isset($fields[(string) $name])) {
                $violations[] = new Violation([(string) $name], 'unexpected_field', 'is not an input this accepts.');
            }
        }

        $states = Conditions::states($fields, $payload);

        foreach ($fields as $name => $field) {
            $raw = array_key_exists($name, $payload) ? $payload[$name] : self::defaultOf($field);
            array_push($violations, ...$this->field($name, $field, $raw, $states[$name] ?? null, $values));
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $values;
    }

    /**
     * One field: its converted value into $values, or what is wrong with it.
     *
     * @param array<string, mixed> $field
     * @param array{visible: bool, required: bool, disabled: bool}|null $state
     * @param array<string, mixed> $values
     * @param-out array<string, mixed> $values
     * @return list<Violation>
     */
    private function field(string $name, array $field, mixed $raw, ?array $state, array &$values): array
    {
        if ($state !== null && (!$state['visible'] || $state['disabled'])) {
            // not shown or not editable now: the default stands, and nothing is required
            $default = self::defaultOf($field);
            $values[$name] = match (true) {
                $default !== null => $this->converter->convert($field, $default),
                $field['type'] === 'boolean' => false,
                $field['type'] === 'booleanGroup' => ValueConverter::flags($field, []),
                default => null,
            };

            return [];
        }

        if ($raw === null || $raw === '') {
            if (($state === null || !$state['required']) && ($field['nullable'] ?? true) === true) {
                $values[$name] = $field['type'] === 'boolean' ? false : null;

                return [];
            }

            // a flag group left out is every flag off, unless it is required()
            if ($field['type'] === 'booleanGroup' && ($state === null || !$state['required']) && !self::isRequired($field)) {
                $values[$name] = ValueConverter::flags($field, []);

                return [];
            }

            return [new Violation([$name], 'required', 'is required.')];
        }

        try {
            $values[$name] = $this->converter->convert($field, $raw);
        } catch (InputViolation $e) {
            return [$e->violation->under($name)];
        }

        return array_map(static fn (Violation $v): Violation => $v->under($name), $this->converter->violationsOfRules($field, $raw));
    }

    /** @param array<string, mixed> $field */
    private static function isRequired(array $field): bool
    {
        return array_any($field['rules'] ?? [], static fn (array $rule): bool => str_ends_with((string) $rule['constraint'], '\\NotBlank'));
    }

    /** @param array<string, mixed> $field */
    private static function defaultOf(array $field): mixed
    {
        return $field['hasDefault'] === true ? $field['default'] : null;
    }
}
