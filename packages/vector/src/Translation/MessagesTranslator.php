<?php

declare(strict_types=1);

namespace Kinetis\Vector\Translation;

/**
 * Translations from PHP files: `{dir}/{locale}.php` returns an array of
 * key => message, nested arrays flattened with dots. Vector's own labels
 * (its built-in settings groups) come from the package's `translations/`,
 * and the application's directory — VECTOR_TRANSLATIONS_DIR — is layered
 * over them. The files are read once, when the worker boots; what they
 * hold is request-neutral. A regional tag falls back to its language
 * (`pt-BR` to `pt`).
 */
final readonly class MessagesTranslator implements TranslatorInterface
{
    /** @param array<string, array<string, string>> $messages locale => key => message */
    public function __construct(private array $messages) {}

    /** @param list<string> $directories later ones override earlier ones */
    public static function fromDirectories(array $directories): self
    {
        $messages = [];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                throw new \InvalidArgumentException("The translations directory \"{$directory}\" does not exist.");
            }

            foreach (glob(rtrim($directory, '/') . '/*.php') ?: [] as $file) {
                $locale = basename($file, '.php');

                if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
                    continue;
                }

                $loaded = require $file;

                if (!is_array($loaded)) {
                    throw new \InvalidArgumentException("{$file} must return an array of translations.");
                }

                $messages[$locale] = [...$messages[$locale] ?? [], ...self::flatten($loaded)];
            }
        }

        return new self($messages);
    }

    #[\Override]
    public function translate(string $key, string $locale, array $parameters = []): ?string
    {
        $message = $this->messages[$locale][$key] ?? $this->messages[strtok($locale, '-') ?: $locale][$key] ?? null;

        if ($message === null || $parameters === []) {
            return $message;
        }

        return strtr($message, array_combine(array_map(static fn (string $name): string => '{' . $name . '}', array_keys($parameters)), array_map('strval', $parameters)));
    }

    /**
     * @param array<array-key, mixed> $messages
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $message) {
            if (is_array($message)) {
                $flat = [...$flat, ...self::flatten($message, "{$prefix}{$key}.")];
            } elseif (is_string($message)) {
                $flat[$prefix . $key] = $message;
            }
        }

        return $flat;
    }
}
