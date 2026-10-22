<?php

declare(strict_types=1);

namespace Kinetis\Vector\RichText;

use Kinetis\Validation\Violation;
use Tiptap\Editor;

/**
 * A rich-text value: the editor's (Tiptap / ProseMirror) JSON document,
 * validated against a fixed schema instead of sanitized as markup.
 *
 * validate() is what a write must pass: valid JSON, a `doc` root, only
 * the node and mark types the field's features allow, only the
 * attributes each type defines, links to http(s), mailto or relative
 * URLs, bounded size and depth. toHtml() renders a stored document for
 * the application's own pages through tiptap-php, configured with the
 * same features, and toPlainText() is the projection exports and search
 * use.
 *
 * Pure: holds only the node types an application added (extend()), set
 * once when it is bound, so one instance serves a worker.
 */
final readonly class Document
{
    /** Every feature a field may allow. */
    public const array FEATURES = ['bold', 'italic', 'underline', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link', 'table', 'image', 'mention'];

    /** A field allows these unless it says otherwise: all but images and mentions, which need a field to come from. */
    public const array DEFAULT_FEATURES = ['bold', 'italic', 'underline', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link', 'table'];

    public const int MAX_BYTES = 262_144;

    public const int MAX_DEPTH = 32;

    public const int MAX_NODES = 20_000;

    /** Always part of the schema. */
    private const array BASE_NODES = ['doc' => [], 'paragraph' => [], 'text' => [], 'hardBreak' => []];

    /**
     * What each feature adds: node and mark types, each with the attributes it defines.
     *
     * @var array<string, array{nodes?: array<string, list<string>>, marks?: array<string, list<string>>}>
     */
    private const array FEATURE_TYPES = [
        'bold' => ['marks' => ['bold' => []]],
        'italic' => ['marks' => ['italic' => []]],
        'underline' => ['marks' => ['underline' => []]],
        'strike' => ['marks' => ['strike' => []]],
        'code' => ['marks' => ['code' => []]],
        'heading' => ['nodes' => ['heading' => ['level']]],
        'list' => ['nodes' => ['bulletList' => [], 'orderedList' => ['start', 'type'], 'listItem' => []]],
        'blockquote' => ['nodes' => ['blockquote' => []]],
        'codeBlock' => ['nodes' => ['codeBlock' => ['language']]],
        'horizontalRule' => ['nodes' => ['horizontalRule' => []]],
        'link' => ['marks' => ['link' => ['href', 'target', 'rel', 'class', 'title']]],
        'table' => ['nodes' => ['table' => [], 'tableRow' => [], 'tableHeader' => ['colspan', 'rowspan', 'colwidth', 'align'], 'tableCell' => ['colspan', 'rowspan', 'colwidth', 'align']]],
        'image' => ['nodes' => ['image' => ['src', 'alt', 'title', 'width', 'height']]],
        'mention' => ['nodes' => ['mention' => ['id', 'label', 'mentionSuggestionChar']]],
    ];

    /** Block nodes: their text ends a line in the plain-text projection. */
    private const array BLOCKS = ['paragraph', 'heading', 'blockquote', 'codeBlock', 'listItem', 'horizontalRule'];

    /** @param list<NodeSpec> $extensions */
    public function __construct(private array $extensions = []) {}

    /** A document that also accepts these application node and mark types. */
    public function extend(NodeSpec ...$specs): self
    {
        return new self([...$this->extensions, ...array_values($specs)]);
    }

    /**
     * What is wrong with $json as a document of a field allowing
     * $features: an empty list when it is valid. Each violation's path
     * is relative to the field (`content/2/marks/0/attrs/href`).
     *
     * @param list<string> $features
     * @param (\Closure(string): bool)|null $imageAllowed whether an image's `src` (a storage key) may be written
     * @return list<Violation>
     */
    public function validate(string $json, array $features, int $maxBytes = self::MAX_BYTES, ?\Closure $imageAllowed = null): array
    {
        if (strlen($json) > $maxBytes) {
            return [new Violation([], 'too_large', 'is larger than ' . intdiv($maxBytes, 1024) . ' KiB.')];
        }

        try {
            // the byte and node limits bound the work; depth is reported by the walk, by node
            $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [new Violation([], 'invalid_document', 'is not a document: it is not valid JSON, or it nests too deeply.')];
        }

        if (!is_array($doc) || ($doc['type'] ?? null) !== 'doc' || !is_array($doc['content'] ?? null)) {
            return [new Violation([], 'invalid_document', 'is not a document: its root must be {"type": "doc", "content": [...]}.')];
        }

        [$nodes, $marks] = $this->schema($features);
        $violations = [];
        $count = 0;
        $this->walk($doc, [], 0, $nodes, $marks, $imageAllowed, $violations, $count);

        if ($count > self::MAX_NODES) {
            return [new Violation([], 'too_large', 'holds more than ' . self::MAX_NODES . ' nodes.')];
        }

        return $violations;
    }

    /**
     * Checks one node and everything under it.
     *
     * @param array<array-key, mixed> $node
     * @param list<int|string> $path
     * @param array<string, list<string>> $nodes
     * @param array<string, list<string>> $marks
     * @param (\Closure(string): bool)|null $imageAllowed
     * @param list<Violation> $violations
     */
    private function walk(array $node, array $path, int $depth, array $nodes, array $marks, ?\Closure $imageAllowed, array &$violations, int &$count): void
    {
        if (++$count > self::MAX_NODES) {
            return;
        }

        if ($depth > self::MAX_DEPTH) {
            $violations[] = new Violation($path, 'too_deep', 'nests deeper than ' . self::MAX_DEPTH . ' levels.');

            return;
        }

        $type = $node['type'] ?? null;

        if (!is_string($type) || !array_key_exists($type, $nodes)) {
            $violations[] = new Violation([...$path, 'type'], 'not_allowed', is_string($type) ? "is \"{$type}\", which this field does not allow." : 'is missing.');

            return;
        }

        if (($extra = array_diff(array_keys($node), ['type', 'attrs', 'content', 'marks', 'text'])) !== []) {
            $violations[] = new Violation($path, 'invalid_node', 'carries ' . implode(', ', $extra) . ', which a node does not have.');
        }

        if ($type === 'text' && (!is_string($node['text'] ?? null) || $node['text'] === '')) {
            $violations[] = new Violation([...$path, 'text'], 'invalid_node', 'must be non-empty text.');
        }

        $this->checkAttrs($node['attrs'] ?? null, $nodes[$type], $type, [...$path, 'attrs'], $violations, $imageAllowed);

        foreach (self::listOf($node['marks'] ?? []) ?? [] as $i => $mark) {
            $markType = is_array($mark) ? ($mark['type'] ?? null) : null;

            if (!is_string($markType) || !array_key_exists($markType, $marks)) {
                $violations[] = new Violation([...$path, 'marks', $i, 'type'], 'not_allowed', is_string($markType) ? "is \"{$markType}\", which this field does not allow." : 'is missing.');

                continue;
            }

            $this->checkAttrs($mark['attrs'] ?? null, $marks[$markType], $markType, [...$path, 'marks', $i, 'attrs'], $violations, $imageAllowed);
        }

        if (isset($node['marks']) && self::listOf($node['marks']) === null) {
            $violations[] = new Violation([...$path, 'marks'], 'invalid_node', 'must be a list.');
        }

        if (isset($node['content'])) {
            $children = self::listOf($node['content']);

            if ($children === null) {
                $violations[] = new Violation([...$path, 'content'], 'invalid_node', 'must be a list of nodes.');

                return;
            }

            foreach ($children as $i => $child) {
                is_array($child)
                    ? $this->walk($child, [...$path, 'content', $i], $depth + 1, $nodes, $marks, $imageAllowed, $violations, $count)
                    : $violations[] = new Violation([...$path, 'content', $i], 'invalid_node', 'must be a node.');
            }
        }
    }

    /** Whether $json is a document holding no text, image or other content. */
    public function isEmpty(string $json): bool
    {
        $doc = json_decode($json, true);

        if (!is_array($doc)) {
            return trim(strip_tags($json)) === ''; // stored HTML
        }

        $empty = static function (mixed $node) use (&$empty): bool {
            if (!is_array($node)) {
                return true;
            }

            $type = $node['type'] ?? null;

            if ($type === 'text') {
                return trim((string) ($node['text'] ?? '')) === '';
            }

            if (!in_array($type, ['doc', 'paragraph', 'hardBreak'], true)) {
                return false; // an image, a rule, a table… is content
            }

            return array_all(is_array($node['content'] ?? null) ? $node['content'] : [], $empty);
        };

        return $empty($doc);
    }

    /**
     * The document's text, one line per block: what an export cell and a
     * search column hold. A stored HTML value reads through the schema.
     */
    public function toPlainText(string $value): string
    {
        $doc = json_decode($value, true);

        if (!is_array($doc)) {
            $doc = (new Editor(['extensions' => $this->extensionsFor(self::FEATURES)]))->setContent($value)->getDocument();
            $doc = is_array($doc) ? $doc : json_decode((string) json_encode($doc), true);
        }

        $text = self::plain($doc);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /** A node's text: blocks end their line, a table row's cells share one. */
    private static function plain(mixed $node): string
    {
        if (!is_array($node)) {
            return '';
        }

        $type = $node['type'] ?? null;
        $children = is_array($node['content'] ?? null) ? $node['content'] : [];

        return match (true) {
            $type === 'text' => (string) ($node['text'] ?? ''),
            $type === 'hardBreak' => "\n",
            $type === 'mention' => '@' . (string) ($node['attrs']['label'] ?? $node['attrs']['id'] ?? ''),
            $type === 'tableRow' => implode(' | ', array_map(static fn (mixed $cell): string => trim(self::plain($cell)), $children)) . "\n",
            in_array($type, self::BLOCKS, true) => rtrim(implode('', array_map(self::plain(...), $children)), "\n") . "\n",
            default => implode('', array_map(self::plain(...), $children)),
        };
    }

    /**
     * The document as HTML, rendered by tiptap-php through exactly the
     * field's features: anything outside them is dropped, text and
     * attributes are escaped. For the application's own pages; the admin
     * renders on the client through the same schema.
     *
     * An image's `src` is a private storage key: $imageUrl maps it to a
     * URL the page may show, or to null to leave the image out. Without
     * it, images keep their key (what an HTML-storing field stores).
     *
     * @param list<string> $features
     * @param (\Closure(string): ?string)|null $imageUrl
     */
    public function toHtml(string $json, array $features = self::DEFAULT_FEATURES, ?\Closure $imageUrl = null): string
    {
        $doc = json_decode($json, true);

        if (!is_array($doc)) {
            return '';
        }

        if ($imageUrl !== null) {
            $doc = self::mapImages($doc, $imageUrl);
        }

        return (new Editor(['extensions' => $this->extensionsFor($features)]))->setContent(self::withRendererLinkAttributes($doc))->getHTML();
    }

    /**
     * The document with every link's `rel` and `class` left to the
     * renderer's defaults (`noopener noreferrer nofollow`, none): a value
     * stored before validate() held them, or written around it, never
     * overrides what keeps the page safe.
     *
     * @param array<array-key, mixed> $node
     * @return array<array-key, mixed>
     */
    private static function withRendererLinkAttributes(array $node): array
    {
        if (is_array($node['marks'] ?? null)) {
            foreach ($node['marks'] as $i => $mark) {
                if (is_array($mark) && ($mark['type'] ?? null) === 'link' && is_array($mark['attrs'] ?? null)) {
                    unset($mark['attrs']['rel'], $mark['attrs']['class']);
                    $node['marks'][$i] = $mark;
                }
            }
        }

        if (is_array($node['content'] ?? null)) {
            $node['content'] = array_map(static fn (mixed $child): mixed => is_array($child) ? self::withRendererLinkAttributes($child) : $child, $node['content']);
        }

        return $node;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param \Closure(string): ?string $imageUrl
     * @return array<array-key, mixed>
     */
    private static function mapImages(array $node, \Closure $imageUrl): array
    {
        if (!is_array($node['content'] ?? null)) {
            return $node;
        }

        $content = [];

        foreach ($node['content'] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'image') {
                $url = is_string($child['attrs']['src'] ?? null) ? $imageUrl($child['attrs']['src']) : null;

                if ($url === null) {
                    continue;
                }

                $child['attrs']['src'] = $url;
            }

            $content[] = is_array($child) ? self::mapImages($child, $imageUrl) : $child;
        }

        $node['content'] = $content;

        return $node;
    }

    /**
     * The storage keys the document's images point at.
     *
     * @return list<string>
     */
    public function imageKeys(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $doc = json_decode($value, true);

        if (!is_array($doc)) {
            preg_match_all('/<img\b[^>]*\bsrc="([^"]+)"/i', $value, $matches);

            return array_values(array_unique(array_map(static fn (string $src): string => html_entity_decode($src), $matches[1])));
        }

        $keys = [];
        $walk = static function (mixed $node) use (&$walk, &$keys): void {
            if (!is_array($node)) {
                return;
            }

            if (($node['type'] ?? null) === 'image' && is_string($node['attrs']['src'] ?? null)) {
                $keys[] = $node['attrs']['src'];
            }

            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                $walk($child);
            }
        };
        $walk($doc);

        return array_values(array_unique($keys));
    }

    /** Whether $feature is one a field may name: built in, or added with extend(). */
    public function knows(string $feature): bool
    {
        return in_array($feature, self::FEATURES, true) || array_any($this->extensions, static fn (NodeSpec $spec): bool => $spec->name === $feature);
    }

    /**
     * The node and mark types a field allowing $features accepts, each with its attributes.
     *
     * @param list<string> $features
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>}
     */
    private function schema(array $features): array
    {
        $nodes = self::BASE_NODES;
        $marks = [];

        foreach ($features as $feature) {
            $nodes = [...$nodes, ...(self::FEATURE_TYPES[$feature]['nodes'] ?? [])];
            $marks = [...$marks, ...(self::FEATURE_TYPES[$feature]['marks'] ?? [])];

            foreach ($this->extensions as $spec) {
                if ($spec->name === $feature) {
                    $spec->kind === 'mark' ? $marks[$spec->name] = $spec->attributes : $nodes[$spec->name] = $spec->attributes;
                }
            }
        }

        return [$nodes, $marks];
    }

    /**
     * @param list<string> $allowed
     * @param list<string|int> $path
     * @param list<Violation> $violations
     * @param (\Closure(string): bool)|null $imageAllowed
     */
    private function checkAttrs(mixed $attrs, array $allowed, string $type, array $path, array &$violations, ?\Closure $imageAllowed): void
    {
        if ($attrs === null) {
            return;
        }

        if (!is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
            $violations[] = new Violation($path, 'invalid_node', 'must be an object.');

            return;
        }

        foreach ($attrs as $name => $value) {
            $name = (string) $name;

            if (!in_array($name, $allowed, true)) {
                $violations[] = new Violation([...$path, $name], 'not_allowed', "is not an attribute of {$type}.");

                continue;
            }

            $problem = match ("{$type}.{$name}") {
                'link.href' => self::href($value),
                'link.target' => $value === null || $value === '_blank' ? null : 'must be _blank or null.',
                // the editor sends its own defaults for these two; a value of the author's own would reach the rendered anchor
                'link.rel' => $value === null || (is_string($value) && preg_match('/^(?:(?:noopener|noreferrer|nofollow)(?: |$))*$/D', $value) === 1) ? null : 'must be noopener, noreferrer or nofollow.',
                'link.class' => $value === null || $value === '' ? null : 'must be empty.',
                'heading.level' => is_int($value) && $value >= 1 && $value <= 6 ? null : 'must be a heading level from 1 to 6.',
                'orderedList.start' => $value === null || (is_int($value) && $value >= 0) ? null : 'must be a whole number.',
                'codeBlock.language' => $value === null || (is_string($value) && preg_match('/^[A-Za-z0-9+#._-]{0,32}$/D', $value) === 1) ? null : 'is not a language name.',
                'tableCell.colspan', 'tableCell.rowspan', 'tableHeader.colspan', 'tableHeader.rowspan' => is_int($value) && $value >= 1 && $value <= 1000 ? null : 'must be a whole number of cells.',
                'tableCell.align', 'tableHeader.align' => $value === null || in_array($value, ['left', 'center', 'right'], true) ? null : 'must be left, center, right or null.',
                'tableCell.colwidth', 'tableHeader.colwidth' => $value === null || (is_array($value) && array_is_list($value) && array_all($value, static fn (mixed $w): bool => is_int($w) && $w >= 0)) ? null : 'must be a list of widths.',
                'image.src' => is_string($value) && $value !== '' && preg_match('~^[A-Za-z0-9/_.-]+$~D', $value) === 1 && !str_contains($value, '..') && ($imageAllowed === null || $imageAllowed($value))
                    ? null : 'is not an image uploaded for this field.',
                'image.width', 'image.height' => $value === null || (is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d{1,5}$/D', $value) === 1) ? null : 'must be a size in pixels.',
                default => $value === null || is_scalar($value) ? null : 'must be text.',
            };

            if ($problem !== null) {
                $violations[] = new Violation([...$path, $name], 'invalid_attribute', $problem);
            }
        }
    }

    /** A link's target: http(s), mailto, or relative to the page. */
    private static function href(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            return 'must be a URL.';
        }

        $compact = preg_replace('/[\x00-\x20\x7F]+/', '', $value) ?? $value;

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $compact, $scheme) === 1) {
            return in_array(strtolower($scheme[0]), ['http:', 'https:', 'mailto:'], true) ? null : 'must be an http(s), mailto or relative link.';
        }

        return null; // relative: a path, a query, a fragment
    }

    /**
     * The tiptap-php extensions for $features — never the highlighting
     * code blocks, which reach the filesystem or a process.
     *
     * @param list<string> $features
     * @return list<\Tiptap\Core\Extension|\Tiptap\Core\Node|\Tiptap\Core\Mark>
     */
    private function extensionsFor(array $features): array
    {
        $on = static fn (string $feature): bool => in_array($feature, $features, true);
        $extensions = [new \Tiptap\Nodes\Document(), new \Tiptap\Nodes\Paragraph(), new \Tiptap\Nodes\Text(), new \Tiptap\Nodes\HardBreak()];

        $table = [
            'bold' => [\Tiptap\Marks\Bold::class],
            'italic' => [\Tiptap\Marks\Italic::class],
            'underline' => [\Tiptap\Marks\Underline::class],
            'strike' => [\Tiptap\Marks\Strike::class],
            'code' => [\Tiptap\Marks\Code::class],
            'heading' => [\Tiptap\Nodes\Heading::class],
            'list' => [\Tiptap\Nodes\BulletList::class, \Tiptap\Nodes\OrderedList::class, \Tiptap\Nodes\ListItem::class],
            'blockquote' => [\Tiptap\Nodes\Blockquote::class],
            'codeBlock' => [\Tiptap\Nodes\CodeBlock::class],
            'horizontalRule' => [\Tiptap\Nodes\HorizontalRule::class],
            'table' => [\Tiptap\Nodes\Table::class, \Tiptap\Nodes\TableRow::class, \Tiptap\Nodes\TableHeader::class, \Tiptap\Nodes\TableCell::class],
            'image' => [\Tiptap\Nodes\Image::class],
        ];

        foreach ($table as $feature => $classes) {
            if ($on($feature)) {
                foreach ($classes as $class) {
                    $extensions[] = new $class();
                }
            }
        }

        if ($on('mention')) {
            // the label as the plain-text projection shows it; tiptap-php appends renderText() output as is, so it is escaped here
            $extensions[] = new \Tiptap\Nodes\Mention(['renderLabel' => static fn (object $node): string => htmlspecialchars(
                '@' . (string) ($node->attrs->label ?? $node->attrs->id ?? ''),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            )]);
        }

        if ($on('link')) {
            // the link mark's own protocol list is wider than the schema's; validate() already held the line
            $extensions[] = new \Tiptap\Marks\Link(['allowedProtocols' => ['http', 'https', 'mailto']]);
        }

        foreach ($this->extensions as $spec) {
            if ($on($spec->name) && $spec->renderer !== null) {
                $extensions[] = new ($spec->renderer)();
            }
        }

        return $extensions;
    }

    /**
     * @return array<int, mixed>|null
     */
    private static function listOf(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }
}
