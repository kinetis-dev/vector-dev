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
 * this request's (or job's) user; the one set of rules the writing
 * endpoint and the bulk ProposeRewrites action both ask, failing closed:
 *
 * - field(): a password field is refused by its type before anything
 *   else; then the field as this user sees it; then a field writing help
 *   is on for (helps()): a text, Markdown or rich-text field, not a
 *   unique string unless it opts in;
 * - writable(): the field on the view's form, not read-only or computed,
 *   and the view's permission (`create`, or `update`) on the resource;
 * - mayUpdate(): that record's own `update`;
 * - sendable(): what ModelBoundary lets a model be sent;
 * - provider(): writing on, configured, budgeted and this user's to use.
 *
 * helps() and offers() answer the same rules as a yes or no, for the
 * admin: offers() is what `capabilities.ai.noWriting` publishes.
 *
 * Today's token budget is AiAvailability's to answer (remaining(),
 * budgetSpent()), asked by the writing endpoint and ProposeRewrites
 * directly, as by every AI endpoint.
 *
 * Refusals are AiRequestExceptions with the status the endpoint answers.
 * Built per request.
 */
final readonly class WritingGuard
{
    /** The field types writing help works on. */
    public const array TYPES = ['string', 'text', 'markdown', 'richtext'];

    /**
     * The field meta key that turns writing help off (false) or on (true)
     * for one field, whatever its uniqueness: `->meta('ai.writing', false)`.
     * Only TYPES are ever helped, and it is independent of
     * ModelBoundary::META_KEY; a field with writing help off may still be
     * sent to the model, and one turned on is still kept back by `ai.send`.
     */
    public const string META_KEY = 'ai.writing';

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

        if (!self::helps($compiled)) {
            throw new AiRequestException("Writing help is off for this field of {$visible->label()}.", 403);
        }

        return [$visible, $compiled];
    }

    /**
     * Whether writing help is on for $field, by its own definition: one of
     * TYPES; then its META_KEY, true or false, when it has one; otherwise
     * not a `string` field marked unique; a unique short string is an
     * identifier (a SKU, a code), not prose. Asks nothing of the user or
     * the model boundary: field() and offers() add those.
     *
     * @param array<string, mixed> $field a compiled field
     */
    public static function helps(array $field): bool
    {
        if (!in_array($field['type'] ?? null, self::TYPES, true)) {
            return false;
        }

        $override = is_array($field['meta'] ?? null) ? ($field['meta'][self::META_KEY] ?? null) : null;

        if (is_bool($override)) {
            return $override;
        }

        return !($field['type'] === 'string' && ($field['unique'] ?? false) === true);
    }

    /**
     * Whether the admin offers writing help on $field of $visible, as this
     * user sees it: helps() and ModelBoundary lets the model be sent it
     * (sendable()'s rules). Whether this user may write the field on a
     * given form is writable()'s, asked per request by the endpoint.
     *
     * @param array<string, mixed> $field a compiled field of $visible
     */
    public function offers(ResourceDescriptor $visible, array $field): bool
    {
        $name = $field['name'] ?? null;

        return self::helps($field)
            && is_string($name)
            && $this->boundary->allows($visible)
            && $this->boundary->sends($visible, $name);
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
}
