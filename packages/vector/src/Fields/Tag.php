<?php

declare(strict_types=1);

namespace Kinetis\Vector\Fields;

use Closure;
use InvalidArgumentException;

/**
 * Free-form text tags, stored as a JSON list of strings in an `array` or
 * `?array` property. Shown as chips and edited in a chips input.
 *
 * A written list is normalized: each tag trimmed, empty ones dropped,
 * duplicates dropped ignoring case (the first spelling stays), the order
 * kept. A tag longer than maxLength() or a list longer than maxTags() is
 * refused, a tag at its own index. suggestions() only proposes values in
 * the input; any other text is a valid tag.
 *
 * A filterable tag field filters by `any` (the default for a bare value)
 * or `all` of a list of tags, each compared exactly as it is stored.
 */
final class Tag extends Field
{
    /** The longest tag by default, in characters. */
    public const int MAX_LENGTH = 50;

    /** The most tags a record holds by default. */
    public const int MAX_TAGS = 20;

    /** @var list<string>|Closure|null */
    private array|Closure|null $suggestions = null;

    private int $maxLength = self::MAX_LENGTH;

    private int $maxTags = self::MAX_TAGS;

    #[\Override]
    public function type(): string
    {
        return 'tag';
    }

    #[\Override]
    protected function hasTextBox(): bool
    {
        return true;
    }

    /**
     * Values the input proposes as the user types: a list, or a closure
     * returning one, run once when the resource compiles. They never
     * restrict what a tag may be.
     *
     * @param list<string>|Closure(): list<string> $suggestions
     */
    public function suggestions(array|Closure $suggestions): static
    {
        $this->suggestions = $suggestions;

        return $this;
    }

    /** The longest a single tag may be, in characters. */
    public function maxLength(int $characters): static
    {
        $this->maxLength = max(1, $characters);

        return $this;
    }

    /** The most tags one record may hold. */
    public function maxTags(int $tags): static
    {
        $this->maxTags = max(1, $tags);

        return $this;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'tags' => ['suggestions' => $this->resolveSuggestions(), 'maxLength' => $this->maxLength, 'maxTags' => $this->maxTags],
        ];
    }

    /** @return list<string> */
    private function resolveSuggestions(): array
    {
        $suggestions = $this->suggestions instanceof Closure ? ($this->suggestions)() : $this->suggestions;

        if ($suggestions === null) {
            return [];
        }

        if (!is_array($suggestions)) {
            throw new InvalidArgumentException("\"{$this->name}\": the suggestions closure must return a list of strings.");
        }

        $list = [];

        foreach ($suggestions as $suggestion) {
            if (!is_string($suggestion)) {
                throw new InvalidArgumentException("\"{$this->name}\": a suggestion is a string, not " . get_debug_type($suggestion) . '.');
            }

            $list[] = $suggestion;
        }

        // a suggestion is a tag as the writer would store it
        return self::normalize($list);
    }

    /**
     * Tags as they are stored: trimmed, without empty ones, duplicates
     * dropped ignoring case with the first spelling kept, in their order.
     *
     * @param list<string> $tags
     * @return list<string>
     */
    public static function normalize(array $tags): array
    {
        $seen = [];
        $list = [];

        foreach ($tags as $tag) {
            $tag = self::trim($tag);
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $list[] = $tag;
        }

        return $list;
    }

    /** One tag as it is stored: without the whitespace around it, Unicode spaces included. */
    public static function trim(string $tag): string
    {
        return preg_replace(['/^\s+/u', '/\s+$/u'], '', $tag) ?? trim($tag);
    }
}
