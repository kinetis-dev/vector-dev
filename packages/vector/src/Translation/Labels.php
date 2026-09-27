<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

/**
 * Translates what the admin shows as text in a descriptor-shaped
 * document — the schema, a settings group, a dashboard, a widget's
 * result — for one locale, at response time: the compiled registry stays
 * locale-free. A string under one of the label keys is looked up as a
 * key and kept as declared when the translator has nothing for it.
 * Values, records and rows are data and are never touched.
 */
final readonly class Labels
{
    /** Keys whose string value is text the admin shows. */
    private const array TEXT = ['label', 'labelSingular', 'group', 'help', 'description', 'confirmation', 'keyLabel', 'valueLabel', 'caption', 'placeholder'];

    /** Keys whose map of strings is text the admin shows. */
    private const array TEXT_MAPS = ['ranges'];

    /** Subtrees that are data, not definitions. */
    private const array DATA = ['values', 'rows', 'points', 'branding', 'minorUnits', 'rules', 'default', 'conditions'];

    public function __construct(private TranslatorInterface $translator) {}

    public function text(string $text, string $locale): string
    {
        return $text === '' ? $text : $this->translator->translate($text, $locale) ?? $text;
    }

    /**
     * @param array<array-key, mixed> $document
     * @return array<array-key, mixed>
     */
    public function document(array $document, string $locale): array
    {
        foreach ($document as $key => $value) {
            if (is_string($key) && in_array($key, self::DATA, true)) {
                continue;
            }

            if (is_string($value) && is_string($key) && in_array($key, self::TEXT, true)) {
                $document[$key] = $this->text($value, $locale);
            } elseif (is_array($value) && is_string($key) && in_array($key, self::TEXT_MAPS, true)) {
                $document[$key] = array_map(fn (mixed $label): mixed => is_string($label) ? $this->text($label, $locale) : $label, $value);
            } elseif (is_array($value)) {
                $document[$key] = $this->document($value, $locale);
            } elseif ($value instanceof \stdClass) {
                $document[$key] = (object) $this->document((array) $value, $locale);
            }
        }

        return $document;
    }
}
