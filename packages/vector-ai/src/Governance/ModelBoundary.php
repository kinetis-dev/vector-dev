<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Governance;

use Kinetis\Vector\Mcp\ResourceDescription;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
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
