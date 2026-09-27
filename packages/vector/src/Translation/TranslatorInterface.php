<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

/**
 * Turns a label the descriptor holds into the viewer's language. Every
 * label a resource, field, action, widget, dashboard or settings group
 * declares is looked up as a key — its English text, or a key such as
 * `shop.products` — and shown as it is when there is no translation.
 * The application binds its own implementation to use its framework's
 * translator; Vector binds MessagesTranslator otherwise.
 */
interface TranslatorInterface
{
    /**
     * The message for `$key` in `$locale` (a language tag such as `de` or
     * `pt-BR`), with `{name}` placeholders replaced, or null when there is none.
     *
     * @param array<string, scalar> $parameters
     */
    public function translate(string $key, string $locale, array $parameters = []): ?string;
}
