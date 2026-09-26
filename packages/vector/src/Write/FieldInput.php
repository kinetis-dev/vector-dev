<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;

/**
 * Validates a JSON object against a list of loose compiled fields — an
 * action's dialog, a settings group — the way RecordInput validates a
 * record: every failure collected into the framework's 422 document.
 */
final readonly class FieldInput
{
    public function __construct(private ValueConverter $converter) {}

    /**
     * @param array<string, array<string, mixed>> $fields field name => compiled field
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed> field name => converted value, for every field (absent ones as null or their default)
     * @throws ValidationException
     */
    public function validate(array $fields, array $payload): array
    {
        $values = [];
        $violations = [];

        foreach ($payload as $name => $raw) {
            if (!isset($fields[(string) $name])) {
                $violations[] = new Violation([(string) $name], 'unexpected_field', 'is not an input this accepts.');
            }
        }

        $states = Conditions::states($fields, $payload);

        foreach ($fields as $name => $field) {
            $raw = array_key_exists($name, $payload) ? $payload[$name] : ($field['hasDefault'] === true ? $field['default'] : null);
            $state = $states[$name] ?? null;

            if ($state !== null && (!$state['visible'] || $state['disabled'])) {
                // not shown or not editable now: the default stands, and nothing is required
                $default = $field['hasDefault'] === true ? $field['default'] : null;
                $values[$name] = $default === null ? ($field['type'] === 'boolean' ? false : null) : $this->converter->convert($field, $default);

                continue;
            }

            if ($raw === null || $raw === '') {
                if ($state !== null && $state['required']) {
                    $violations[] = new Violation([$name], 'required', 'is required.');
                } elseif (($field['nullable'] ?? true) === true) {
                    $values[$name] = $field['type'] === 'boolean' ? false : null;
                } else {
                    $violations[] = new Violation([$name], 'required', 'is required.');
                }

                continue;
            }

            try {
                $values[$name] = $this->converter->convert($field, $raw);
            } catch (InputViolation $e) {
                $violations[] = $e->violation->under($name);

                continue;
            }

            foreach ($this->converter->violationsOfRules($field, $raw) as $violation) {
                $violations[] = $violation->under($name);
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $values;
    }
}
