<?php

declare(strict_types=1);

namespace Kinetis\Vector\Import;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;

/**
 * Turns one CSV row into the JSON body a create or an update takes,
 * through the mapping the admin chose: text is parsed by each field's
 * value type (the same parser list filters use), an empty cell is null, a
 * manyToMany takes `a|b|c` identifiers. What comes out is still raw
 * input — the writer validates it like any request.
 *
 * An import creates records (`create`), updates the records a key column
 * finds (`update`), or does whichever a row needs (`upsert`). The key is
 * the resource's own key or a field marked unique(), and must be mapped.
 */
final readonly class RowMapper
{
    public const array MODES = ['create', 'update', 'upsert'];

    public function __construct(private ValueConverter $converter) {}

    /**
     * The mapping's fields must exist, be written by the mode's view
     * (create, update, or either for an upsert — a row then refuses what
     * its own path does not take), and not be named twice; update and
     * upsert need a key, mapped, that identifies one record.
     *
     * @param array<array-key, mixed> $mapping CSV header => field name ('' skips the column)
     * @return array<string, string>
     */
    public function validateMapping(ResourceDescriptor $resource, array $mapping, string $mode = 'create', ?string $key = null): array
    {
        $clean = [];
        $used = [];

        if (!in_array($mode, self::MODES, true)) {
            throw ValidationException::fromViolations([new Violation(['mode'], 'invalid_choice', 'must be create, update or upsert.')]);
        }

        $key = $key === '' ? null : $key;
        $violations = self::keyViolations($resource, $mapping, $mode, $key);
        $views = match ($mode) {
            'create' => ['create'],
            'update' => ['update'],
            default => ['create', 'update'],
        };
        $on = $mode === 'upsert' ? 'create or update' : $mode;

        foreach ($mapping as $header => $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $compiled = $resource->field($field);
            $writable = $compiled !== null && $compiled['readonly'] !== true && array_intersect($views, $compiled['views']) !== []
                && !in_array($compiled['type'], ['id', 'hasOne', 'hasMany', 'computed'], true);

            if (!$writable && !($field === $key && $mode !== 'create')) {
                $violations[] = new Violation(['mapping', (string) $header], 'invalid_choice', "\"{$field}\" is not a field this resource accepts on {$on}.");

                continue;
            }

            if (isset($used[$field])) {
                $violations[] = new Violation(['mapping', (string) $header], 'duplicate', "\"{$field}\" is mapped twice.");

                continue;
            }

            $used[$field] = true;
            $clean[(string) $header] = $field;
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $clean;
    }

    /**
     * A create matches no rows, so it takes no key; an update or upsert
     * needs one that identifies a record, mapped to a column.
     *
     * @param array<array-key, mixed> $mapping
     * @return list<Violation>
     */
    private static function keyViolations(ResourceDescriptor $resource, array $mapping, string $mode, ?string $key): array
    {
        if ($mode === 'create') {
            return $key === null ? [] : [new Violation(['key'], 'unexpected_field', 'matches rows to records, which a create-only import does not do.')];
        }

        $keyField = $key === null ? null : $resource->field($key);

        if ($keyField === null || ($key !== $resource->primaryKeyField() && ($keyField['unique'] ?? false) !== true)) {
            return [new Violation(['key'], 'invalid_choice', 'must be the resource\'s key or a unique field: ' . implode(', ', self::keys($resource)) . '.')];
        }

        if (!in_array($key, $mapping, true)) {
            return [new Violation(['key'], 'invalid_choice', "\"{$key}\" must be mapped to a column.")];
        }

        return [];
    }

    /**
     * The fields an update or upsert may match rows by: the key, and every unique field.
     *
     * @return list<string>
     */
    public static function keys(ResourceDescriptor $resource): array
    {
        return array_values(array_unique([$resource->primaryKeyField(), ...array_keys(array_filter($resource->fields(), static fn (array $f): bool => ($f['unique'] ?? false) === true))]));
    }

    /**
     * A mapping guessed from the headers: a header equal to a field's
     * name or label, ignoring case and punctuation.
     *
     * @param list<string> $headers
     * @return array<string, string>
     */
    public function suggest(ResourceDescriptor $resource, array $headers): array
    {
        $normalize = static fn (string $s): string => strtolower(preg_replace('/[^a-z0-9]/i', '', $s) ?? '');
        $byKey = [];

        foreach ($resource->fields() as $name => $field) {
            // anything an import may write, and the key an update matches by
            $importable = $field['readonly'] !== true && array_intersect(['create', 'update'], $field['views']) !== []
                && !in_array($field['type'], ['id', 'hasOne', 'hasMany', 'computed'], true);

            if (!$importable && $name !== $resource->primaryKeyField()) {
                continue;
            }

            $byKey[$normalize($name)] = $name;
            $byKey[$normalize((string) $field['label'])] ??= $name;
        }

        $mapping = [];
        $used = [];

        foreach ($headers as $header) {
            $field = $byKey[$normalize($header)] ?? null;

            if ($field !== null && !isset($used[$field])) {
                $mapping[$header] = $field;
                $used[$field] = true;
            }
        }

        return $mapping;
    }

    /**
     * The key a row carries, typed as the field is: null when its cell is empty.
     *
     * @param array<string, string> $mapping
     * @param array<string, string> $row
     */
    public function keyOf(ResourceDescriptor $resource, array $mapping, array $row, string $key): mixed
    {
        $header = array_search($key, $mapping, true);
        $raw = $header === false ? '' : trim($row[$header] ?? '');

        if ($raw === '') {
            return null;
        }

        $field = $resource->field($key) ?? throw new \LogicException("{$key} is not a field.");

        try {
            return $key === $resource->primaryKeyField()
                ? $this->converter->identifier($resource->primaryKeyType(), $raw)
                : $this->converter->fromQuery($field, $raw);
        } catch (InputViolation $e) {
            throw ValidationException::fromViolations([$e->violation->under($key)]);
        }
    }

    /**
     * @param array<string, string> $mapping
     * @param array<string, string> $row
     * @param string|null $except a field left out of the body: the key an update matched by
     * @return array<string, mixed>
     */
    public function payload(ResourceDescriptor $resource, array $mapping, array $row, ?string $except = null): array
    {
        $payload = [];
        $violations = [];

        foreach ($mapping as $header => $name) {
            if ($name === $except) {
                continue;
            }

            $field = $resource->field($name);

            if ($field === null) {
                continue;
            }

            $raw = trim($row[$header] ?? '');

            if ($raw === '') {
                // an empty cell is "not given": the writer applies its own required rules
                if ($field['type'] === 'manyToMany') {
                    $payload[$name] = [];
                }

                continue;
            }

            try {
                $payload[$name] = $this->cell($field, $raw);
            } catch (InputViolation $e) {
                $violations[] = $e->violation->under($name);
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $payload;
    }

    /**
     * A non-empty cell as the value a JSON body carries for its field.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function cell(array $field, string $raw): mixed
    {
        if ($field['type'] === 'manyToMany') {
            return array_values(array_filter(array_map('trim', explode('|', $raw)), static fn (string $v): bool => $v !== ''));
        }

        if ($field['type'] === 'belongsTo') {
            return $this->converter->fromQuery($field, $raw);
        }

        $value = $this->converter->fromQuery($field, $raw);

        // back to the text a JSON body carries: the writer converts the payload again
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d\TH:i:s.v\Z'),
            $value instanceof \Kinetis\Orm\Date => (string) $value,
            default => $value,
        };
    }
}
