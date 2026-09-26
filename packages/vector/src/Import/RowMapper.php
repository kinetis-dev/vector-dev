<?php

declare(strict_types=1);

namespace Kinetis\Vector\Import;

use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\InputViolation;
use Kinetis\Vector\Write\ValueConverter;

/**
 * Turns one CSV row into the JSON body a create takes, through the
 * mapping the admin chose: text is parsed by each field's value type
 * (the same parser list filters use), an empty cell is null, a
 * manyToMany takes `a|b|c` identifiers. What comes out is still raw
 * input — the writer validates it like any request.
 */
final readonly class RowMapper
{
    public function __construct(private ValueConverter $converter) {}

    /**
     * The mapping's fields must exist, be writable on create, and not
     * be named twice.
     *
     * @param array<array-key, mixed> $mapping CSV header => field name ('' skips the column)
     * @return array<string, string>
     */
    public function validateMapping(ResourceDescriptor $resource, array $mapping): array
    {
        $clean = [];
        $used = [];
        $violations = [];

        foreach ($mapping as $header => $field) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            $compiled = $resource->field($field);

            if ($compiled === null || $compiled['readonly'] === true || !in_array('create', $compiled['views'], true) || in_array($compiled['type'], ['id', 'hasOne', 'hasMany'], true)) {
                $violations[] = new Violation(['mapping', (string) $header], 'invalid_choice', "\"{$field}\" is not a field this resource accepts on create.");

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
            if ($field['readonly'] === true || !in_array('create', $field['views'], true) || in_array($field['type'], ['id', 'hasOne', 'hasMany'], true)) {
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
     * @param array<string, string> $mapping
     * @param array<string, string> $row
     * @return array<string, mixed>
     */
    public function payload(ResourceDescriptor $resource, array $mapping, array $row): array
    {
        $payload = [];
        $violations = [];

        foreach ($mapping as $header => $name) {
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
                if ($field['type'] === 'manyToMany') {
                    $payload[$name] = array_values(array_filter(array_map('trim', explode('|', $raw)), static fn (string $v): bool => $v !== ''));
                } elseif ($field['type'] === 'belongsTo') {
                    $payload[$name] = $this->converter->fromQuery($field, $raw);
                } else {
                    $value = $this->converter->fromQuery($field, $raw);
                    $payload[$name] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d\TH:i:s.v\Z') : $value;
                }
            } catch (InputViolation $e) {
                $violations[] = $e->violation->under($name);
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        return $payload;
    }
}
