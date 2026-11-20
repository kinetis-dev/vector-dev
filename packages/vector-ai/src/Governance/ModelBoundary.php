<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Governance;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Mcp\ResourceDescription;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Widgets\VectorWidget;
use Kinetis\VectorAi\Settings\AiSettingsReader;
use Kinetis\VectorAi\Settings\DataDefault;

/**
 * The one place that decides what of the admin a model may be sent, and
 * builds it. Its rules, in order:
 *
 * 1. Always kept back, whatever is configured: password, file and image
 *    fields, and every field this user's `viewField` policy hides — the
 *    resource is read through ResourceService::visible() first.
 * 2. A resource opts out whole with `#[Resource(meta: ['ai.send' => false])]`.
 * 3. A field opts out with `->meta('ai.send', false)`.
 *
 * With the *Record data sent to the model* setting on `allowlist`, the
 * sense inverts: only a resource, and within it only a field, that opts
 * in with `ai.send` true is sent. Built per request: the user and the
 * settings are this request's.
 */
final readonly class ModelBoundary
{
    /** The meta key a resource or field opts out (false) or in (true) with. */
    public const string META_KEY = 'ai.send';

    /** What a tool's answer is, in its place, when it may not be sent. */
    public const string UNAVAILABLE = 'this resource is not available to the assistant';

    /** The longest string sent, in characters; a longer one is cut and marked. */
    public const int MAX_STRING = 2000;

    /** What follows a string cut at MAX_STRING. */
    public const string TRUNCATED = '… [truncated]';

    /** The most rows of one page sent. */
    public const int MAX_ROWS = 25;

    /** The tools whose answer is sent as it is, strings cut: they carry no record data. */
    public const array PASSES = ['vector_resources'];

    /** Field types never sent, whatever the metadata says. */
    private const array NEVER = ['password', 'file', 'image'];

    public function __construct(
        private ResourceService $resources,
        private AiSettingsReader $settings,
    ) {}

    /** Whether anything of $resource may be sent to a model. */
    public function allows(ResourceDescriptor $resource): bool
    {
        return $this->passes($resource->meta()[self::META_KEY] ?? null);
    }

    /**
     * What a model is told of $resource: its fields as this user sees them
     * and the rules above let through — per field its name, label, type,
     * filter operators, choices (capped the way vector_describe caps them),
     * how a money or date value is written, and the label of a relation's
     * target where that target may be sent too. No record data.
     *
     * @param array<string, bool> $permissions this user's, from ResourceService::permissions()
     * @return array{resource: string, label: string, fields: list<array<string, mixed>>}
     * @throws \LogicException when the resource does not allow it: allows() is asked first
     */
    public function schema(ResourceDescriptor $visible, array $permissions): array
    {
        if (!$this->allows($visible)) {
            throw new \LogicException("{$visible->slug()} is kept from the model.");
        }

        // read as this user sees it, whatever the caller passed
        $visible = $this->resources->visible($visible);
        $compiled = $visible->fields();
        $described = ResourceDescription::of($visible, $permissions);
        $fields = [];

        foreach ($described['fields'] as $field) {
            $own = $compiled[$field['name']] ?? null;

            if ($own === null || !$this->sendsField($own)) {
                continue;
            }

            $fields[] = array_filter([
                'name' => $field['name'],
                'label' => $field['label'],
                'type' => $field['type'],
                'operators' => $field['filter'] ?? [],
                'format' => $field['format'] ?? null,
                'money' => $field['money'] ?? null,
                'options' => $field['options'] ?? null,
                'choices' => $field['choices'] ?? null,
                'relation' => $this->relationLabel($own),
            ], static fn (mixed $value): bool => $value !== null);
        }

        return ['resource' => $visible->slug(), 'label' => $visible->label(), 'fields' => $fields];
    }

    /**
     * One record as a model may be sent it: the fields the rules above let
     * through, and the primary key always — it names the record, as
     * `resource#id`, and is no data of its own. A belongsTo or hasOne keeps
     * its id, and its label and carried columns only as far as the target
     * and those of its fields may be sent (reference()). Every string
     * longer than MAX_STRING characters is cut there, with TRUNCATED after it. Nothing
     * of a resource kept from the model is sent: an empty record.
     *
     * @param array<array-key, mixed> $record a record as ResourceService serializes it for this user
     * @return array<string, mixed>
     */
    public function record(ResourceDescriptor $visible, array $record): array
    {
        if (!$this->allows($visible)) {
            return [];
        }

        $visible = $this->resources->visible($visible);
        $fields = $visible->fields();
        $key = $visible->primaryKeyField();
        $sent = [];

        foreach ($record as $name => $value) {
            $name = (string) $name;

            if ($name === '_trashed') {
                $sent[$name] = $value === true;

                continue;
            }

            $field = $fields[$name] ?? null;

            if ($field === null || ($name !== $key && !$this->sendsField($field))) {
                continue;
            }

            $sent[$name] = $this->value($field, $value);
        }

        return $sent;
    }

    /**
     * One page of a list as a model may be sent it: record() on each row,
     * at most MAX_ROWS of them, and the page's position — no summaries,
     * which could total a field kept back. `omittedRows` counts the rows
     * left out.
     *
     * @param array<array-key, mixed> $page a page as ResourceService::list() answers it
     * @return array<string, mixed>
     */
    public function page(ResourceDescriptor $visible, array $page): array
    {
        $rows = is_array($page['data'] ?? null) ? array_values($page['data']) : [];
        $sent = ['data' => array_map(
            fn (mixed $row): array => is_array($row) ? $this->record($visible, $row) : [],
            array_slice($rows, 0, self::MAX_ROWS),
        )];

        foreach (['currentPage', 'perPage', 'total', 'lastPage', 'hasMore', 'nextCursor'] as $position) {
            if (array_key_exists($position, $page)) {
                $sent[$position] = $page[$position];
            }
        }

        if (count($rows) > self::MAX_ROWS) {
            $sent['omittedRows'] = count($rows) - self::MAX_ROWS;
        }

        return $sent;
    }

    /**
     * What a model is sent of one tool's answer, by the tool's name:
     *
     * - `vector_describe`: the description for reading, its fields cut as record() cuts them;
     * - `vector_query`: page(); `vector_get`: record() of its `data`;
     * - `vector_widget`: the widget's data, strings cut and a table's
     *   columns withheld by its linked resource left out — refused for a
     *   resource widget of a resource kept from the model, a widget whose
     *   link leads to one, and a table whose rows link to records of one;
     * - `vector_resources`, listed in PASSES: the index as it is, strings cut;
     * - any other tool: refused.
     *
     * A refusal is `{"error": UNAVAILABLE}`, as is a describe, query or get
     * of a resource kept from the model, or one this user cannot see.
     *
     * @param array<array-key, mixed> $arguments the model's arguments to the tool
     */
    public function toolResult(string $tool, array $arguments, mixed $result): mixed
    {
        if (in_array($tool, self::PASSES, true)) {
            return $this->truncated($result);
        }

        if (!is_array($result)) {
            return self::refusal();
        }

        if ($tool === 'vector_widget') {
            return $this->widget($arguments, $result);
        }

        if (!in_array($tool, ['vector_describe', 'vector_query', 'vector_get'], true)) {
            return self::refusal();
        }

        $visible = $this->sendable($arguments['resource'] ?? null);

        if ($visible === null) {
            return self::refusal();
        }

        return match ($tool) {
            'vector_describe' => $this->description($visible, $result),
            'vector_query' => $this->page($visible, $result),
            default => [
                'data' => is_array($result['data'] ?? null) ? $this->record($visible, $result['data']) : null,
                'permissions' => $result['permissions'] ?? null,
            ],
        };
    }

    /**
     * The resource $slug names, as this user sees it, when it exists for
     * them, they may list it (`viewAny`) and it may be sent to a model;
     * otherwise null.
     */
    public function sendable(mixed $slug): ?ResourceDescriptor
    {
        if (!is_string($slug) || $slug === '') {
            return null;
        }

        try {
            $visible = $this->resources->resource($slug);
        } catch (VectorException | AuthorizationException) {
            return null;
        }

        // a resource this user may not list is not theirs to ask about: not even its label is sent
        if (($this->resources->permissions($visible)['viewAny'] ?? false) !== true) {
            return null;
        }

        return $this->allows($visible) ? $visible : null;
    }

    /**
     * Whether a model may be sent $name of $visible — and so may name it
     * in a filter or a sort: the primary key always; otherwise a field
     * this user sees that the rules above let through. Nothing of a
     * resource kept from the model. The one field rule record(),
     * description() and GovernedTool ask.
     */
    public function sends(ResourceDescriptor $visible, string $name): bool
    {
        if (!$this->allows($visible)) {
            return false;
        }

        $visible = $this->resources->visible($visible);

        if ($name === $visible->primaryKeyField()) {
            return true;
        }

        $field = $visible->fields()[$name] ?? null;

        return $field !== null && $this->sendsField($field);
    }

    /**
     * Whether a model may search $visible with `q`: it is searchable, every
     * field search matches — by LIKE, by its full-text index or, for a
     * belongsTo, by the related record's title — passes sends(), and each
     * searched relation's title could be sent as its label is
     * (reference()). Otherwise which records a term matches would tell
     * the model what those fields hold.
     */
    public function searches(ResourceDescriptor $visible): bool
    {
        if (!$this->allows($visible)) {
            return false;
        }

        $visible = $this->resources->visible($visible);

        if (!$visible->searchable()) {
            return false;
        }

        foreach ([...array_keys($visible->searchColumns()), ...array_keys($visible->fullText()['columns'] ?? [])] as $name) {
            if (!$this->sends($visible, (string) $name)) {
                return false;
            }
        }

        foreach ($visible->searchRelations() as $name => $relation) {
            $target = $this->resources->registry()->resources()[$relation['resource']] ?? null;

            if (!$this->sends($visible, (string) $name) || $target === null || !$this->sends($target, $this->resources->visible($target)->titleField())) {
                return false;
            }
        }

        return true;
    }

    /**
     * The order a list of $visible takes when the model names none: the
     * resource's default sort without the fields sends() refuses, or its
     * primary key when none is left — an order by a field kept back would
     * tell the model how its values rank.
     *
     * @return list<array{field: string, order: 'asc'|'desc'}>
     */
    public function defaultSort(ResourceDescriptor $visible): array
    {
        $visible = $this->resources->visible($visible);
        $kept = array_values(array_filter($visible->defaultSort(), fn (array $sort): bool => $this->sends($visible, $sort['field'])));

        return $kept !== [] ? $kept : [['field' => $visible->primaryKeyField(), 'order' => 'asc']];
    }

    /** @return array{error: string} */
    private static function refusal(): array
    {
        return ['error' => self::UNAVAILABLE];
    }

    /**
     * vector_describe's answer for a model that only reads: its fields cut
     * as record() cuts a record — the primary key and what the rules let
     * through — and every other place a field is named (the title field,
     * the default sort) cut the same way. Search is described only where
     * searches() lets `q` through, and the default sort is defaultSort():
     * what is described is what GovernedTool lets a query use. What only a
     * write needs goes: the actions, and each field's rules, defaults,
     * conditions and writability.
     *
     * @param array<array-key, mixed> $described
     * @return array<array-key, mixed>
     */
    private function description(ResourceDescriptor $visible, array $described): array
    {
        $visible = $this->resources->visible($visible);
        $compiled = $visible->fields();
        $key = $visible->primaryKeyField();
        $sends = fn (mixed $name): bool => is_string($name) && isset($compiled[$name]) && ($name === $key || $this->sendsField($compiled[$name]));

        // q is refused where search would match a field kept back (GovernedTool): nothing says it is searchable
        $searches = $this->searches($visible);
        $dropped = ['writable', 'required', 'nullable', 'unique', 'default', 'rules', 'conditions', ...($searches ? [] : ['searchable'])];
        $fields = [];

        foreach (is_array($described['fields'] ?? null) ? $described['fields'] : [] as $field) {
            if (is_array($field) && $sends($field['name'] ?? null)) {
                $fields[] = array_diff_key($field, array_flip($dropped));
            }
        }

        $described['fields'] = $fields;
        $described['titleField'] = $sends($described['titleField'] ?? null) ? $described['titleField'] : null;
        $described['search'] = $searches ? array_values(array_filter(is_array($described['search'] ?? null) ? $described['search'] : [], $sends)) : [];
        // the order GovernedTool gives a query that names none
        $described['defaultSort'] = $this->defaultSort($visible);
        unset($described['actions']);
        $described = $this->truncated($described);

        return is_array($described) ? $described : [];
    }

    /**
     * A widget's answer: refused for a resource widget of a resource kept
     * from the model, for any widget whose `link` leads to records of one,
     * and for a table whose rows do; otherwise its data, strings cut. A
     * widget computes its own figures, so there is no field to cut by but
     * one: a table row's key that is a field of the resource its link
     * (the row's own, or the table's) leads to, and one the boundary would
     * not send of it, is left out — with its column.
     *
     * @param array<array-key, mixed> $arguments
     * @param array<array-key, mixed> $result
     */
    private function widget(array $arguments, array $result): mixed
    {
        $resource = $arguments['resource'] ?? '';

        if ($resource !== '' && $this->sendable($resource) === null) {
            return self::refusal();
        }

        $data = $result['data'] ?? null;

        if (!is_array($data)) {
            return $this->truncated($result);
        }

        $linked = self::linkedSlug($data['link'] ?? null);

        if ($linked !== null && !$this->linkedSendable($linked)) {
            return self::refusal();
        }

        if (($data['type'] ?? null) === VectorWidget::TABLE) {
            $withheld = [];

            foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $i => $row) {
                if (!is_array($row)) {
                    continue;
                }

                $slug = self::linkedSlug($row['_link'] ?? null);

                if ($slug !== null && !$this->linkedSendable($slug)) {
                    return self::refusal();
                }

                $slug ??= $linked;

                foreach (array_keys($row) as $key) {
                    if ($slug !== null && $this->withheld($slug, (string) $key)) {
                        unset($data['rows'][$i][$key]);
                        $withheld[(string) $key] = true;
                    }
                }
            }

            if (is_array($data['columns'] ?? null)) {
                $data['columns'] = array_values(array_filter($data['columns'], fn (mixed $column): bool => !is_array($column)
                    || !is_string($column['key'] ?? null)
                    || (!isset($withheld[$column['key']]) && ($linked === null || !$this->withheld($linked, $column['key'])))));
            }
        }

        $result['data'] = $data;

        return $this->truncated($result);
    }

    /** The slug a widget's link (`/r/{slug}…`) leads to, or null when it leads to no resource. */
    private static function linkedSlug(mixed $link): ?string
    {
        return is_string($link) && preg_match('#^/r/([^/?\#]+)#', $link, $m) === 1 ? $m[1] : null;
    }

    /** Whether $key names a field of the resource $slug, sendable as a whole, that sends() refuses. */
    private function withheld(string $slug, string $key): bool
    {
        $target = $this->resources->registry()->resources()[$slug] ?? null;

        return $target !== null && isset($target->fields()[$key]) && !$this->sends($target, $key);
    }

    /** Whether the resource a widget's link leads to may be sent; an unknown one may not. */
    private function linkedSendable(string $slug): bool
    {
        $target = $this->resources->registry()->resources()[$slug] ?? null;

        return $target !== null && $this->allows($target);
    }

    /**
     * One field's value: a belongsTo or hasOne reference, `{id, label}`,
     * through reference() — under the target's own rules — every string
     * cut. A hasMany or manyToMany value is only its count.
     *
     * @param array<string, mixed> $field a compiled field
     */
    private function value(array $field, mixed $value): mixed
    {
        $slug = $field['relation']['resource'] ?? null;
        $target = is_string($slug) ? ($this->resources->registry()->resources()[$slug] ?? null) : null;

        // a hasOne value is `{id, label}` too: the related record's id and title, as a belongsTo's
        if (in_array($field['type'] ?? null, ['belongsTo', 'hasOne'], true)) {
            return is_array($value) ? $this->reference($target, $value) : null;
        }

        return $this->truncated($value);
    }

    /**
     * A belongsTo or hasOne value, `{id, label, ...carried}`, as a model may be sent
     * it: the id always; the label — the target's title field — only when
     * the target may be sent and its title field passes the rules (or is
     * its primary key); each carried column only when that target field
     * passes them. What is left is cut like any other value.
     *
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function reference(?ResourceDescriptor $target, array $value): array
    {
        $sent = ['id' => $value['id'] ?? null];

        if ($target === null || !$this->allows($target)) {
            return $sent;
        }

        $target = $this->resources->visible($target);
        $fields = $target->fields();
        $key = $target->primaryKeyField();
        $sends = fn (string $name): bool => $name === $key || (isset($fields[$name]) && $this->sendsField($fields[$name]));

        foreach ($value as $name => $carried) {
            $name = (string) $name;

            if ($name === 'id') {
                continue;
            }

            if ($name === 'label' ? $sends($target->titleField()) : $sends($name)) {
                $sent[$name] = $carried;
            }
        }

        $sent = $this->truncated($sent);

        return is_array($sent) ? $sent : [];
    }

    /** $value with every string longer than MAX_STRING characters cut there, at any depth. */
    private function truncated(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING) . self::TRUNCATED : $value;
        }

        if (is_array($value)) {
            return array_map($this->truncated(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $field a compiled field */
    private function sendsField(array $field): bool
    {
        if (in_array($field['type'] ?? null, self::NEVER, true)) {
            return false;
        }

        $meta = is_array($field['meta'] ?? null) ? $field['meta'] : [];

        return $this->passes($meta[self::META_KEY] ?? null);
    }

    /** Whether an `ai.send` value lets a resource or field through under the configured default. */
    private function passes(mixed $send): bool
    {
        return DataDefault::tryFrom($this->settings->string('dataDefault')) === DataDefault::Allowlist
            ? $send === true
            : $send !== false;
    }

    /**
     * The label of the resource a relation points to, when that resource
     * may be sent too; otherwise null, and the field says only its type.
     *
     * @param array<string, mixed> $field a compiled field
     */
    private function relationLabel(array $field): ?string
    {
        $slug = $field['relation']['resource'] ?? null;

        if (!is_string($slug)) {
            return null;
        }

        $target = $this->resources->registry()->resources()[$slug] ?? null;

        return $target !== null && $this->allows($target) ? $target->label() : null;
    }
}
