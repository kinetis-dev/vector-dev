<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Kinetis\Vector\RichText\Document;

/**
 * Long-form content edited in a word-processor-like editor and stored as
 * the editor's JSON document — a tree the server validates against a
 * fixed schema, never markup. The entity property is a string on a TEXT
 * or JSON column.
 *
 * `features()` is the schema's allow-list (bold, italic, underline,
 * strike, code, heading, list, blockquote, codeBlock, horizontalRule,
 * link, table, image, mention, and any node Document::extend() added);
 * every one but image and mention by default. `imageField()` names the
 * file or image field whose uploads images come from — an image stores a
 * storage key, never a URL; `mentions()` names the relation whose
 * records `@` offers. `searchable()` needs `textField()`: a second
 * string property the writer fills with the plain text on every write.
 * `storesHtml()` is the opt-in for an existing HTML column.
 */
final class RichText extends Field
{
    /** @var list<string> */
    private array $features = Document::DEFAULT_FEATURES;

    private int $maxBytes = Document::MAX_BYTES;

    private ?string $imageField = null;

    private ?string $mentions = null;

    private ?string $textField = null;

    private bool $storesHtml = false;

    #[\Override]
    public function type(): string
    {
        return 'richtext';
    }

    /** @param list<string> $features */
    public function features(array $features): self
    {
        $this->features = array_values(array_unique($features));

        return $this;
    }

    /** The largest document, in bytes of its JSON; 256 KiB by default. */
    public function maxBytes(int $bytes): self
    {
        $this->maxBytes = $bytes;

        return $this;
    }

    /** The file or image field of the resource whose upload endpoint images come from; adds the image feature. */
    public function imageField(string $field): self
    {
        $this->imageField = $field;
        $this->features = array_values(array_unique([...$this->features, 'image']));

        return $this;
    }

    /** The belongsTo or manyToMany field whose records `@` mentions; adds the mention feature. */
    public function mentions(string $relation): self
    {
        $this->mentions = $relation;
        $this->features = array_values(array_unique([...$this->features, 'mention']));

        return $this;
    }

    /** A string property of the entity the writer fills with the plain text, which search then reads. */
    public function textField(string $property): self
    {
        $this->textField = $property;

        return $this;
    }

    /**
     * Stores HTML instead of the document, for an existing HTML column:
     * the server renders what the editor sends through the schema, and
     * the admin reads it back through the same schema, so anything outside
     * it is dropped on the next save. A document loses no fidelity; HTML
     * does.
     */
    public function storesHtml(bool $storesHtml = true): self
    {
        $this->storesHtml = $storesHtml;

        return $this;
    }

    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'richtext' => [
                'features' => $this->features,
                'imageField' => $this->imageField,
                'mentions' => $this->mentions,
                'storesHtml' => $this->storesHtml,
                'maxBytes' => $this->maxBytes,
                'textField' => $this->textField,
            ],
        ];
    }
}
