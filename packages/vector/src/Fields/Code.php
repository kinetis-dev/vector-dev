<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use InvalidArgumentException;

/**
 * Source text (a snippet of HTML or CSS, a query, a configuration) in a
 * string property, edited in a code editor that highlights its
 * language(). On the wire it is the text as it was typed. It sorts,
 * filters and searches as a text column does.
 *
 * language() picks the highlighting, one of LANGUAGES (`plain` by
 * default); lineNumbers() turns the gutter's line numbers off for short
 * snippets; rows() sets how many lines the editor shows before it
 * scrolls. None of them changes what is stored.
 */
final class Code extends Field
{
    /** The languages the editor highlights; `plain` highlights nothing. */
    public const array LANGUAGES = ['plain', 'json', 'html', 'css', 'javascript', 'typescript', 'sql', 'php', 'yaml', 'markdown', 'xml'];

    /** The fewest and most lines rows() takes. */
    public const int MIN_ROWS = 2;
    public const int MAX_ROWS = 40;

    private string $language = 'plain';

    private bool $lineNumbers = true;

    private int $rows = 10;

    #[\Override]
    public function type(): string
    {
        return 'code';
    }

    #[\Override]
    protected function hasTextBox(): bool
    {
        return true;
    }

    /** The language the editor highlights: one of LANGUAGES. */
    public function language(string $language): static
    {
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException("\"{$this->name}\": the editor highlights " . implode(', ', self::LANGUAGES) . ", not \"{$language}\".");
        }

        $this->language = $language;

        return $this;
    }

    /** Whether the editor numbers its lines (it does by default). */
    public function lineNumbers(bool $lineNumbers = true): static
    {
        $this->lineNumbers = $lineNumbers;

        return $this;
    }

    /** How many lines the editor shows before it scrolls, from MIN_ROWS to MAX_ROWS (10 by default). */
    public function rows(int $rows): static
    {
        if ($rows < self::MIN_ROWS || $rows > self::MAX_ROWS) {
            throw new InvalidArgumentException("\"{$this->name}\": rows() takes " . self::MIN_ROWS . ' to ' . self::MAX_ROWS . " lines, not {$rows}.");
        }

        $this->rows = $rows;

        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [...parent::toArray(), 'code' => ['language' => $this->language, 'lineNumbers' => $this->lineNumbers, 'rows' => $this->rows]];
    }
}
