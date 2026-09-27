<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Put;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Store\PreferenceStore;
use Psr\Http\Message\ServerRequestInterface;

/** Per-user UI preferences: column state per resource, density, theme. */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class PreferencesController
{
    public function __construct(
        private PreferenceStore $preferences,
        private CurrentUserInterface $user,
        private \Kinetis\Vector\Translation\LocaleResolver $locales,
    ) {}

    /** @return array{data: array<string, mixed>} */
    #[Get('/preferences')]
    public function index(): array
    {
        return ['data' => $this->preferences->all((string) $this->user->id())];
    }

    /**
     * Merges the given keys; a null value removes a key.
     *
     * @return array{data: array<string, mixed>}
     */
    #[Put('/preferences')]
    public function put(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        try {
            $body = trim($raw) === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($body) || array_is_list($body) && $body !== []) {
            throw ValidationException::fromViolations([new Violation([], 'type_mismatch', 'The request body must be an object of preference keys.')]);
        }

        $violations = [];

        foreach ($body as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z0-9][a-z0-9.\-_]{0,79}$/i', $key) !== 1) {
                $violations[] = new Violation([(string) $key], 'invalid_key', 'is not a preference key.');
            } elseif (strlen(json_encode($value, JSON_THROW_ON_ERROR)) > PreferenceStore::MAX_VALUE_BYTES) {
                $violations[] = new Violation([$key], 'too_large', 'must be at most 16 KiB.');
            } elseif ($key === 'locale' && $value !== null && (!is_string($value) || \Kinetis\Vector\Translation\Locales::match($value, $this->locales->languages()) === null)) {
                // only a language the administrator has switched on: one switched off is not chosen, not even through the API
                $violations[] = new Violation([$key], 'invalid_choice', 'is not a language on offer.', ['choices' => $this->locales->languages()]);
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }

        /** @var array<string, mixed> $body */
        $this->preferences->put((string) $this->user->id(), $body);

        return ['data' => $this->preferences->all((string) $this->user->id())];
    }
}
