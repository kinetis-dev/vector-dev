<?php

declare(strict_types=1);

namespace Kinetis\Vector\Mcp;

use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Write\RecordInput;

/**
 * One resource as an agent needs it to read and write records through
 * the MCP tools, what vector_describe answers. Unlike the UI's
 * descriptor (layouts, table settings, page sizes), it states per field
 * what a call can do with it: whether a create must send it, whether a
 * create or update may write it, its rules, its choices, the relation it
 * points to and the filter operators vector_query accepts; and per action
 * its dialog. Built from the descriptor as this user sees it, so a field
 * a policy hides is absent; keys that would be empty are left out.
 */
final class ResourceDescription
{
    /** A choice list longer than this is described, not listed: every ISO 4217 code, say. */
    private const int MAX_OPTIONS = 50;

    /** What a value of a type looks like on the wire, where the type alone does not say. */
    private const array FORMATS = [
        'date' => 'YYYY-MM-DD',
        'datetime' => 'ISO 8601 in UTC, such as 2026-09-27T14:30:00.000Z',
        'color' => '#rrggbb',
        'belongsTo' => 'the related record\'s id',
        'manyToMany' => 'a list of the related records\' ids; the list replaces the current one',
        'keyValue' => 'an object of string values',
        'json' => 'a JSON object or array',
        'markdown' => 'Markdown text',
        'richtext' => 'a rich-text document: ProseMirror/Tiptap JSON, sent as a string',
        'file' => 'a storage key from an upload; files cannot be uploaded through these tools',
        'image' => 'a storage key from an upload; images cannot be uploaded through these tools',
    ];

    /**
     * @param array<string, mixed> $permissions this user's, from ResourceService::permissions()
     * @return array<string, mixed>
     */
    public static function of(ResourceDescriptor $resource, array $permissions): array
    {
        $public = $resource->toArray();
        $internal = $resource->fields();
        $fields = [];

        foreach ($public['fields'] as $field) {
            $fields[] = self::field($field, $internal[$field['name']] ?? $field);
        }

        $description = [
            'resource' => $public['slug'],
            'label' => $public['label'],
            'labelSingular' => $public['labelSingular'],
            'group' => $public['group'] ?? null,
            'key' => ['field' => $public['primaryKey']['field'], 'type' => $public['primaryKey']['type']],
            'titleField' => $public['titleField'],
            'softDelete' => $public['softDelete'] !== null,
            'permissions' => $permissions,
            'search' => $public['table']['search'],
            'defaultSort' => $public['table']['defaultSort'],
            // vector_query's pages of this resource carry no total
            'withoutCount' => $resource->withoutCount() ? true : null,
            'fields' => $fields,
            'actions' => array_map(self::action(...), $public['actions']),
        ];

        return array_filter($description, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $field the published field
     * @param array<string, mixed> $compiled the field as the writer checks it
     * @return array<string, mixed>
     */
    private static function field(array $field, array $compiled): array
    {
        $computed = $field['computed'] ?? null;
        $writable = self::writable($field);
        $written = $writable !== [];
        $description = [
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'valueType' => $field['valueType'] ?? null,
            'format' => self::format($field),
            'multiple' => ($field['multiple'] ?? false) === true ? true : null,
            'help' => $field['help'] ?? null,
            'placeholder' => $field['placeholder'] ?? null,
            'writable' => $writable,
            // what the writer itself demands of a create; only a field a call writes has any of these
            'required' => $written ? self::required($field, $compiled, $writable) : null,
            'nullable' => $written ? $field['nullable'] ?? true : null,
            'unique' => ($field['unique'] ?? false) === true ? true : null,
            'default' => $written && ($field['hasDefault'] ?? false) === true ? $field['default'] : null,
            'rules' => $written ? self::rules($field['rules'] ?? []) : null,
            'richtext' => isset($field['richtext']['features']) ? ['features' => $field['richtext']['features']] : null,
            'computed' => $computed,
            'relation' => isset($field['relation']) ? ['kind' => $field['relation']['kind'], 'resource' => $field['relation']['resource']] : null,
            'money' => $field['type'] === 'money' ? self::money($field) : null,
            'filter' => ($field['filterable'] ?? false) === true && ($field['operators'] ?? []) !== [] ? $field['operators'] : null,
            'filterHelp' => ($field['filterable'] ?? false) === true ? self::filterHelp($field, $compiled) : null,
            'flags' => self::flags($field, $compiled),
            'rows' => self::rows($field),
            'sortable' => ($field['sortable'] ?? false) === true ? true : null,
            'searchable' => ($field['searchable'] ?? false) === true ? true : null,
        ];

        // `writable` stays when empty: an absent key would leave a read-only field ambiguous
        return [...self::compact($description), 'writable' => $writable, ...self::options($field), ...self::conditions($field)];
    }

    /**
     * What a value looks like on the wire, where the type alone does not say.
     *
     * @param array<string, mixed> $field
     */
    private static function format(array $field): ?string
    {
        $multiple = ($field['multiple'] ?? false) === true;

        if ($field['type'] === 'tag') {
            $tags = $field['tags'] ?? [];

            return sprintf(
                'a list of free-text tags, at most %d of at most %d characters each; stored trimmed, without empty ones, each at most once ignoring case (the first spelling kept), in the order sent',
                (int) ($tags['maxTags'] ?? 20),
                (int) ($tags['maxLength'] ?? 50),
            );
        }

        if ($field['type'] === 'repeater') {
            $repeater = $field['repeater'];
            $count = "{$repeater['min']} to {$repeater['max']} rows";

            if (($repeater['mode'] ?? 'json') !== 'relationship') {
                return "a list of {$count}, each an object of the fields in `rows` and no others; stored in the order sent";
            }

            $order = ($repeater['sortable'] ?? false) === true ? '; the list order is the rows\' order' : '';

            return "a list of {$count}, each an object of the fields in `rows` plus the `id` of an existing row to keep; a row with an id updates that row, one without creates it, and a row left out is deleted, all saved with the record"
                . $order;
        }

        return match (true) {
            $field['type'] === 'country' && $multiple => 'a list of ISO 3166-1 alpha-2 country codes in upper case, each at most once; stored in the order the options are listed',
            $field['type'] === 'country' => 'an ISO 3166-1 alpha-2 country code in upper case',
            $field['type'] === 'select' && $multiple => 'a list of option values, each at most once; stored in the order the options are listed',
            $field['type'] === 'booleanGroup' => 'an object of option key to true or false, every key present when read; a key a write leaves out is false',
            $field['type'] === 'dateRange' => ($field['range']['kind'] ?? 'date') === 'date'
                ? 'an object {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}, both set and to never before from, or null for no range'
                : 'an object {"from": …, "to": …} of ISO 8601 instants in UTC, both set and to never before from, or null for no range',
            $field['type'] === 'code' => ($field['code']['language'] ?? 'plain') === 'plain' ? 'source text' : "source text in {$field['code']['language']}",
            default => self::FORMATS[$field['type']] ?? null,
        };
    }

    /**
     * How a field's list filter reads, where its operators alone do not say.
     *
     * @param array<string, mixed> $field the published field
     * @param array<string, mixed> $compiled
     */
    private static function filterHelp(array $field, array $compiled): ?string
    {
        if ($field['type'] === 'booleanGroup') {
            return 'any: at least one of the comma-separated option keys is on; all: every one of them is on'
                . (isset($compiled['flags']) ? '; each flag also filters on its own as filter[' . $field['name'] . '.<key>]=true or false' : '');
        }

        return match (true) {
            ($field['multiple'] ?? false) === true => 'any: holds at least one of the comma-separated option values; all: holds every one of them',
            $field['type'] === 'tag' => 'any (a bare value): holds at least one of the comma-separated tags; all: holds every one of them; tags match exactly as stored',
            default => null,
        };
    }

    /**
     * A columns-mode flag group's flags, each filtered or sorted by name
     * (`group.key`) as a boolean of its own.
     *
     * @param array<string, mixed> $field the published field
     * @param array<string, mixed> $compiled
     * @return list<array<string, mixed>>|null
     */
    private static function flags(array $field, array $compiled): ?array
    {
        if (!isset($compiled['flags'])) {
            return null;
        }

        $flags = [];

        foreach (array_keys($compiled['flags']) as $key) {
            $flags[] = self::compact([
                'name' => "{$field['name']}.{$key}",
                'filter' => ($field['filterable'] ?? false) === true ? ['eq'] : null,
                'sortable' => ($field['flagsSortable'] ?? false) === true ? true : null,
            ]);
        }

        return $flags;
    }

    /**
     * The fields one row of a repeater holds: its own in JSON mode, the
     * child resource's it names in relationship mode.
     *
     * @param array<string, mixed> $field
     * @return list<array<string, mixed>>|null
     */
    private static function rows(array $field): ?array
    {
        if ($field['type'] !== 'repeater') {
            return null;
        }

        if (isset($field['repeater']['rowFields'])) {
            return array_values(array_map(static fn (array $row): array => [...self::compact([
                'name' => $row['name'],
                'type' => $row['type'],
                'format' => self::format($row),
                'required' => ($row['nullable'] ?? true) !== true,
                'rules' => self::rules($row['rules'] ?? []),
            ]), ...self::options($row)], $field['repeater']['rowFields']));
        }

        return array_values(array_map(static fn (string $name): array => ['name' => $name], $field['repeater']['fields'] ?? []));
    }

    /**
     * The views a call may write the field in: none for a read-only,
     * computed, key or inverse relation field.
     *
     * @param array<string, mixed> $field the published field
     * @return list<string>
     */
    private static function writable(array $field): array
    {
        $computed = $field['computed'] ?? null;

        return $field['readonly'] === true || $computed !== null || in_array($field['type'], ['id', 'hasOne', 'hasMany'], true)
            ? []
            : array_values(array_intersect(['create', 'update'], $field['views']));
    }

    /**
     * Whether a create must send the field, as the writer itself demands.
     *
     * @param array<string, mixed> $field the published field
     * @param array<string, mixed> $compiled the field as the writer checks it
     * @param list<string> $writable
     */
    private static function required(array $field, array $compiled, array $writable): bool
    {
        return in_array('create', $writable, true) && RecordInput::requiredOnCreate($compiled) && ($field['hasDefault'] ?? false) !== true;
    }

    /**
     * @param array<string, mixed> $action the published action
     * @return array<string, mixed>
     */
    private static function action(array $action): array
    {
        $fields = [];

        foreach ($action['fields'] ?? [] as $field) {
            $fields[] = [...self::compact([
                'name' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
                'format' => self::format($field),
                'help' => $field['help'] ?? null,
                'placeholder' => $field['placeholder'] ?? null,
                // a dialog's field is required unless it may be empty or has a default
                'required' => ($field['nullable'] ?? true) !== true && ($field['hasDefault'] ?? false) !== true,
                'default' => ($field['hasDefault'] ?? false) === true ? $field['default'] : null,
                'rules' => self::rules($field['rules'] ?? []),
            ]), ...self::options($field)];
        }

        return self::compact([
            'name' => $action['name'],
            'label' => $action['label'],
            'confirmation' => $action['confirmation'] ?? null,
            'destructive' => ($action['destructive'] ?? false) === true ? true : null,
            // standalone: run with no records; otherwise on record ids
            'standalone' => ($action['standalone'] ?? false) === true ? true : null,
            'queued' => ($action['queued'] ?? false) === true ? true : null,
            'fields' => $fields,
        ]);
    }

    /**
     * A rule as its constraint's short name and arguments: `MaxLength(40)`.
     *
     * @param list<array{constraint: string, args: list<mixed>}> $rules
     * @return list<string>
     */
    private static function rules(array $rules): array
    {
        return array_map(static function (array $rule): string {
            $name = substr((string) strrchr('\\' . $rule['constraint'], '\\'), 1);

            return $rule['args'] === [] ? $name : $name . '(' . implode(', ', array_map(static fn (mixed $arg): string => json_encode($arg, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rule['args'])) . ')';
        }, $rules);
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function options(array $field): array
    {
        $options = $field['options'] ?? [];

        if (!is_array($options) || $options === []) {
            return [];
        }

        return count($options) > self::MAX_OPTIONS
            ? ['choices' => count($options) . ' values, such as ' . implode(', ', array_slice(array_column($options, 'value'), 0, 5))]
            : ['options' => array_map(static fn (array $o): array => ['value' => $o['value'], 'label' => $o['label']], $options)];
    }

    /**
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function money(array $field): array
    {
        $props = is_array($field['props'] ?? null) ? $field['props'] : [];

        return self::compact([
            'unit' => 'minor units (cents)',
            'currency' => $props['currency'] ?? null,
            'currencyField' => $props['currencyField'] ?? null,
        ]);
    }

    /**
     * When the field is shown, required or disabled depends on other fields' values.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function conditions(array $field): array
    {
        $conditions = $field['conditions'] ?? [];

        return self::compact([
            'visibleWhen' => $conditions['visible'] ?? [],
            'requiredWhen' => $conditions['required'] ?? [],
            'disabledWhen' => $conditions['disabled'] ?? [],
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
