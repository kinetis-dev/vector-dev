<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

/**
 * Markdown source, for technical content: stored as the text an author
 * writes, edited WYSIWYG in the admin (with a source toggle) and shown
 * rendered and sanitized. For business content, RichText.
 */
final class Markdown extends Field
{
    /** What the editor's toolbar offers. */
    public const array TOOLBAR = ['bold', 'italic', 'strike', 'code', 'heading', 'list', 'blockquote', 'codeBlock', 'horizontalRule', 'link'];

    /** @var list<string> */
    private array $toolbar = self::TOOLBAR;

    public function type(): string
    {
        return 'markdown';
    }

    /**
     * The toolbar's buttons, from bold, italic, strike, code, heading,
     * list, blockquote, codeBlock, horizontalRule and link. The source
     * stays Markdown whatever the toolbar offers.
     *
     * @param list<string> $buttons
     */
    public function toolbar(array $buttons): self
    {
        if (($unknown = array_diff($buttons, self::TOOLBAR)) !== []) {
            throw new \InvalidArgumentException("\"{$this->name}\": the toolbar has no " . implode(', ', $unknown) . '; it takes ' . implode(', ', self::TOOLBAR) . '.');
        }

        $this->toolbar = $buttons;

        return $this;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'markdown' => ['toolbar' => $this->toolbar]];
    }
}
