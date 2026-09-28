<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * Validates a request body against a resource and converts it to the
 * values an entity holds: enum cases, UTC instants, related entities.
 * Every failure across every field is collected into the framework's
 * own ValidationException, so the 422 document is the standard one.
 *
 * Rules run as the framework runs them: each `{constraint, args}` pair
 * becomes a fresh, pure Constraint instance and checks the raw value.
 */
final readonly class RecordInput
{
    public function __construct(
        private ValueConverter $converter,
        private \Kinetis\Vector\Files\FileService $files,
        private \Kinetis\Vector\Files\VectorStorage $storage,
        private \Kinetis\Vector\Query\ResourceService $service,
        private \Kinetis\Vector\RichText\Document $document,
    ) {}

    /**
     * A field whose conditions hide or disable it under the submitted
     * form is skipped, not written; one whose requiredWhen holds must end
     * up non-empty. On create, a field the request leaves out takes its
     * default, validated like a value that was sent.
     *
     * @param array<array-key, mixed> $payload the decoded JSON object
     * @param array<string, mixed> $current the stored record, serialized, when updating
     * @return array<string, mixed> field name => converted value, for every field the payload named
     * @throws ValidationException
     */
    public function validate(ResourceDescriptor $resource, array $payload, bool $partial, array $current = []): array
    {
        $values = [];
        $violations = [];
        $view = $partial ? 'update' : 'create';

        // a new record starts from each field's default, as the form shows it; one the request sends wins
        if (!$partial) {
            foreach ($resource->fields() as $name => $field) {
                if (($field['hasDefault'] ?? false) === true && !array_key_exists($name, $payload) && $field['readonly'] !== true && in_array('create', $field['views'], true)) {
                    $payload[$name] = $field['default'];
                }
            }
        }
        // The form the user saw: the stored record overlaid with what arrives,
        // which is what visibleWhen / requiredWhen / disabledWhen read.
        $states = Conditions::states($resource->fields(), [...$current, ...array_intersect_key($payload, $resource->fields())]);

        foreach ($payload as $name => $raw) {
            $name = (string) $name;

            if ($name === 'version') {
                continue; // the optimistic-lock token, read by the writer
            }

            $field = $resource->field($name);

            if ($field === null || !in_array($view, $field['views'], true) || in_array($field['type'], ['hasOne', 'hasMany'], true)) {
                $violations[] = new Violation([$name], 'unexpected_field', 'is not a field this resource accepts.');

                continue;
            }

            $state = $states[$name] ?? null;

            if ($state !== null && (!$state['visible'] || $state['disabled'])) {
                continue; // the form neither shows nor edits it now, so its value is not written
            }

            if ($field['readonly'] === true) {
                $violations[] = new Violation([$name], 'read_only', 'is read-only.');

                continue;
            }

            if (ValueConverter::keepsStoredPassword($field, $raw)) {
                continue; // nothing typed into a hashed password: the stored hash stays; a create still reports it required
            }

            // With storage configured, a new file value must be a key the upload endpoint issued for this field.
            if (in_array($field['type'], ['file', 'image'], true) && is_string($raw) && $raw !== '' && $raw !== ($current[$name] ?? null)
                && $this->storage->available() && !$this->files->issued($resource, $name, $raw)) {
                $violations[] = new Violation([$name], 'invalid_file', 'is not a file uploaded for this field.');

                continue;
            }

            if ($field['type'] === 'richtext') {
                array_push($violations, ...$this->richText($resource, $field, $raw, $current, $values));

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

        $reported = static fn (string $name): bool => array_any($violations, static fn (Violation $v): bool => $v->path === [$name]);

        foreach ($resource->fields() as $name => $field) {
            $depends = $field['relation']['dependsOn'] ?? null;

            if ($depends !== null && !$reported($name) && !$reported($depends['field'])) {
                $violation = $this->dependentChoice($resource, $name, $depends, $payload, $values, $current);

                if ($violation !== null) {
                    $violations[] = $violation;
                }
            }
        }

        if (!$partial) {
            foreach ($resource->fields() as $name => $field) {
                $state = $states[$name] ?? null;
                $hidden = $state !== null && (!$state['visible'] || $state['disabled']);

                if (!$hidden && self::requiredOnCreate($field) && !array_key_exists($name, $values) && !$reported($name)) {
                    $violations[] = new Violation([$name], 'required', 'is required.');
                }
            }
        }

        foreach ($states as $name => $state) {
            $field = $resource->fields()[$name];

            if (!$state['required'] || !$state['visible'] || $state['disabled'] || !in_array($view, $field['views'], true) || $reported($name)) {
                continue;
            }

            $final = array_key_exists($name, $values) ? $values[$name] : ($current[$name] ?? null);

            if (Conditions::isEmpty($final)) {
                $violations[] = new Violation([$name], 'required', 'is required.');
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $values;
    }

    /**
     * A dependent choice written or left in place must be one the options
     * offer for the dependency's value. Only a change to either side is
     * checked, so a record stored before the rule still saves unchanged.
     *
     * @param array{field: string, on: string, label: string, column: string} $depends
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $values
     * @param array<string, mixed> $current
     */
    private function dependentChoice(ResourceDescriptor $resource, string $name, array $depends, array $payload, array $values, array $current): ?Violation
    {
        $other = $depends['field'];
        $choice = array_key_exists($name, $values) ? self::key($payload[$name] ?? null) : self::key($current[$name] ?? null);
        $value = array_key_exists($other, $values) ? self::key($payload[$other] ?? null) : self::key($current[$other] ?? null);
        $changed = (array_key_exists($name, $values) && $choice !== self::key($current[$name] ?? null))
            || (array_key_exists($other, $values) && $value !== self::key($current[$other] ?? null));

        if (!$changed || $choice === null) {
            return null;
        }

        try {
            $allowed = $value !== null && $this->service->dependentChoiceAllowed(
                $resource->fields()[$name],
                $this->converter->fromQuery($resource->fields()[$name], (string) $choice),
                $this->converter->fromQuery($resource->fields()[$other], (string) $value),
            );
        } catch (InputViolation) {
            $allowed = false;
        }

        return $allowed ? null : new Violation([$name], 'invalid_choice', 'is not a choice for this ' . mb_strtolower($depends['label']) . '.', ['dependsOn' => $other]);
    }

    /** A relation or scalar value as the key it compares by: `{id, label}` is its id. */
    private static function key(mixed $value): int|string|null
    {
        if (is_array($value)) {
            $value = $value['id'] ?? null;
        }

        return is_int($value) || is_string($value) && $value !== '' ? $value : (is_bool($value) ? (int) $value : null);
    }

    /**
     * A rich-text value: its JSON document checked against the field's
     * schema, images only from keys issued for its image field (or
     * already in the stored document), an empty document as no value.
     * Stored as the document, or as HTML rendered through the schema when
     * the field stores HTML; its plain text fills the text property.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $current
     * @param array<string, mixed> $values
     * @param-out array<string, mixed> $values
     * @return list<Violation>
     */
    private function richText(ResourceDescriptor $resource, array $field, mixed $raw, array $current, array &$values): array
    {
        $name = (string) $field['name'];
        $rich = $field['richtext'];

        if ($raw !== null && !is_string($raw)) {
            return [new Violation([$name], 'invalid_document', 'must be the document as JSON text.')];
        }

        $raw = $raw === null || $raw === '' || $this->document->isEmpty($raw) ? null : $raw;

        if ($raw !== null) {
            $stored = $this->document->imageKeys(is_string($current[$name] ?? null) ? $current[$name] : null);
            $imageField = $rich['imageField'];
            $allowed = fn (string $key): bool => in_array($key, $stored, true)
                || ($imageField !== null && ($this->storage->available()
                    ? $this->files->issued($resource, $imageField, $key)
                    : str_starts_with($key, "vector/{$resource->slug()}/{$imageField}/")));
            $problems = $this->document->validate($raw, $rich['features'], $rich['maxBytes'], $allowed);

            if ($problems !== []) {
                return array_map(static fn (Violation $v): Violation => $v->under($name), $problems);
            }
        }

        $violations = array_map(static fn (Violation $v): Violation => $v->under($name), $this->converter->violationsOfRules($field, $raw));

        if ($violations !== []) {
            return $violations;
        }

        $values[$name] = $raw === null ? null : ($rich['storesHtml'] ? $this->document->toHtml($raw, $rich['features']) : $raw);

        $textField = $rich['textField'] ?? null;

        if (is_string($textField)) {
            $values[$textField] = $raw === null ? null : $this->document->toPlainText($raw);
        }

        return [];
    }

    /** @param array<string, mixed> $field */
    public static function requiredOnCreate(array $field): bool
    {
        if ($field['readonly'] === true || !in_array('create', $field['views'], true)) {
            return false;
        }

        if (in_array($field['type'], ['hasOne', 'hasMany', 'manyToMany', 'id'], true)) {
            return false;
        }

        if ($field['nullable'] ?? true) {
            return array_any($field['rules'], static fn (array $rule): bool => str_ends_with($rule['constraint'], '\\NotBlank'));
        }

        return true;
    }
}
