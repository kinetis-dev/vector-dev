<?php

declare(strict_types=1);

namespace Kinetis\Vector\RichText;

/**
 * A node or mark type an application adds to the rich-text schema — a
 * callout, an embed — registered with Document::extend() so the server
 * accepts what a runtime extension's editor produces
 * (`window.Vector.defineRichTextExtension()`). A field then names it in
 * `features()`.
 *
 * `renderer` is a tiptap-php Node or Mark class for Document::toHtml();
 * without one, the node's content renders without a tag of its own.
 */
final readonly class NodeSpec
{
    /**
     * @param list<string> $attributes the attribute names its JSON may carry
     * @param 'node'|'mark' $kind
     * @param class-string<\Tiptap\Core\Node|\Tiptap\Core\Mark>|null $renderer
     */
    public function __construct(
        public string $name,
        public array $attributes = [],
        public string $kind = 'node',
        public ?string $renderer = null,
    ) {
        if (preg_match('/^[a-z][A-Za-z0-9]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("A rich-text node name is camelCase letters and digits, not \"{$name}\".");
        }
    }
}
