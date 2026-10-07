<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

/** No translations: every label is shown as it is declared. */
final readonly class NullTranslator implements TranslatorInterface
{
    #[\Override]
    public function translate(string $key, string $locale, array $parameters = []): ?string
    {
        return null;
    }
}
