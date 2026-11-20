<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Writing;

use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\VectorAi\Auth\AiAvailability;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Governance\ModelBoundary;
use Kinetis\VectorAi\Http\AiRequestException;
use Kinetis\VectorAi\Providers\ResolvedProvider;

/**
 * What decides whether a field's text may be sent for writing help, as
 * this request's (or job's) user — the one set of rules the writing
 * endpoint and the bulk ProposeRewrites action both ask, failing closed:
 *
 * - field(): a password field is refused by its type before anything
 *   else; then the field as this user sees it; then a text, Markdown or
 *   rich-text field only;
 * - writable(): the field on the view's form, not read-only or computed,
 *   and the view's permission (`create`, or `update`) on the resource;
 * - mayUpdate(): that record's own `update`;
 * - sendable(): what ModelBoundary lets a model be sent;
 * - provider(): writing on, configured, budgeted and this user's to use;
 * - remaining() and budgetLeft(): today's token budget not spent.
 *
 * Refusals are AiRequestExceptions with the status the endpoint answers.
 * Built per request.
 */
final readonly class WritingGuard
{
    /** The field types writing help works on. */
    public const array TYPES = ['string', 'text', 'markdown', 'richtext'];

    public function __construct(
        private AiAvailability $availability,
        private ResourceService $resources,
        private ModelBoundary $boundary,
    ) {}

    /**
     * The resource as this user sees it and its field $field.
     *
     * @return array{ResourceDescriptor, array<string, mixed>}
     * @throws AiRequestException 403 for a password or non-text field, 404 for a field this user does not see
     */
    public function field(string $resource, string $field): array
    {
        // a password field is never sent, whatever else holds: asked of the compiled field, before this user's view of it
        if (($this->resources->registry()->resource($resource)->field($field)['type'] ?? null) === 'password') {
            throw new AiRequestException('A password field is never sent to AI.', 403);
        }

        $visible = $this->resources->resource($resource);
        $compiled = $visible->field($field) ?? throw new AiRequestException("{$visible->label()} has no field {$field}.", 404);

        if (!in_array($compiled['type'] ?? null, self::TYPES, true)) {
            throw new AiRequestException('Writing help works on text, Markdown and rich-text fields only.', 403);
        }

        return [$visible, $compiled];
    }

    /**
     * Refuses unless $field is on $view's form ('create' or 'update'),
     * neither read-only nor computed, and this user has $view on the
     * resource. A record's own `update` is mayUpdate()'s.
     *
     * @param array<string, mixed> $field
     * @throws AiRequestException 403
     */
    public function writable(ResourceDescriptor $visible, array $field, string $view): void
    {
        $views = is_array($field['views'] ?? null) ? $field['views'] : [];

        if (($field['readonly'] ?? false) === true || ($field['computed'] ?? null) !== null || !in_array($view, $views, true)) {
            throw new AiRequestException("This field of {$visible->label()} cannot be written on the {$view} form.", 403);
        }

        if (($this->resources->permissions($visible)[$view] ?? false) !== true) {
            throw new AiRequestException("This account may not {$view} {$visible->label()}.", 403);
        }
    }

    /**
     * Whether this user may update $record, as ResourceService serialized it for them.
     *
     * @param array<string, mixed> $record
     */
    public function mayUpdate(ResourceDescriptor $visible, array $record): bool
    {
        return $this->resources->recordPermissions($visible, $record)['update'] === true;
    }

    /**
     * Refuses unless ModelBoundary lets $field of $visible be sent: the
     * resource and the field not opted out, under the configured default.
     *
     * @throws AiRequestException 403
     */
    public function sendable(ResourceDescriptor $visible, string $field): void
    {
        if (!$this->boundary->allows($visible) || !$this->boundary->sends($visible, $field)) {
            throw new AiRequestException("This field of {$visible->label()} is kept from AI.", 403);
        }
    }

    /**
     * Writing's provider for this user: AiAvailability::provider().
     *
     * @throws AiRequestException 403 when writing is off, unconfigured, unbudgeted or not theirs
     */
    public function provider(): ResolvedProvider
    {
        return $this->availability->provider(Feature::Writing);
    }

    /** How many of today's tokens this user has left. */
    public function remaining(): int
    {
        return $this->availability->remaining();
    }

    /** Whether this user has any of today's token budget left. */
    public function budgetLeft(): bool
    {
        return $this->remaining() > 0;
    }
}
