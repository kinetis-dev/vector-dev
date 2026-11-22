<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Config;

use Kinetis\Config\Config;

/**
 * The operator's AI configuration, read once at boot and bound on
 * AppScope: the providers VECTOR_AI_PROVIDERS lists, each from its own
 * VECTOR_AI_<NAME>_* keys, and the limits every call runs under.
 * Immutable and request-neutral.
 *
 *     VECTOR_AI_PROVIDERS=openai,bedrock
 *     VECTOR_AI_<NAME>_TYPE=openai|anthropic|openai-like|ollama|bedrock|fake
 *     VECTOR_AI_<NAME>_BASE_URL=https://…   (openai-like and ollama; http:// allowed for those two)
 *     VECTOR_AI_<NAME>_KEY=…                 (optional; else an administrator seals one)
 *     VECTOR_AI_<NAME>_MODELS=a,b            (the allow-list; empty admits any)
 *     VECTOR_AI_<NAME>_REGION=eu-central-1   (bedrock)
 *     VECTOR_AI_TIMEOUT=90  VECTOR_AI_MAX_RESPONSE_BYTES=4194304  VECTOR_AI_MAX_OUTPUT_TOKENS=2048
 *
 * A provider's keys are scoped through Config::scopedKey(), the way
 * named connections are: `AI_TYPE` scoped to `openai` is `AI_OPENAI_TYPE`,
 * under the `VECTOR_` prefix; a dash in a name is an underscore in its
 * keys. A configuration that cannot work stops the boot with a message
 * naming the key at fault, as VectorDefaults does for VECTOR_CURRENCY.
 */
final readonly class AiConfig
{
    /** A provider's name: what settings and the credentials API call it. */
    public const string NAME = '/^[a-z0-9-]{1,40}$/D';

    private const string REGION = '/^[a-z0-9-]{1,40}$/D';

    /**
     * @param array<string, ProviderConfig> $providers by name, in the order VECTOR_AI_PROVIDERS lists them
     */
    public function __construct(
        public array $providers = [],
        public float $timeout = 90.0,
        public int $maxResponseBytes = 4194304,
        public int $maxOutputTokens = 2048,
    ) {}

    /**
     * @throws \InvalidArgumentException naming the key at fault
     */
    public static function fromConfig(Config $config): self
    {
        $providers = [];

        foreach (self::list($config->string('VECTOR_AI_PROVIDERS', '')) as $name) {
            if (preg_match(self::NAME, $name) !== 1 || $name === 'default') {
                throw new \InvalidArgumentException("VECTOR_AI_PROVIDERS names \"{$name}\": a provider name is 1 to 40 lower-case letters, digits and dashes, and not \"default\".");
            }

            if (isset($providers[$name])) {
                throw new \InvalidArgumentException("VECTOR_AI_PROVIDERS names \"{$name}\" twice.");
            }

            $providers[$name] = self::parseProvider($config, $name);
        }

        return new self(
            $providers,
            self::positiveFloat($config, 'VECTOR_AI_TIMEOUT', 90.0),
            self::positiveInt($config, 'VECTOR_AI_MAX_RESPONSE_BYTES', 4194304),
            self::positiveInt($config, 'VECTOR_AI_MAX_OUTPUT_TOKENS', 2048),
        );
    }

    /** The configuration key of $suffix (`TYPE`, `BASE_URL`, …) for the provider $name. */
    public static function key(string $suffix, string $name): string
    {
        return 'VECTOR_' . Config::scopedKey("AI_{$suffix}", str_replace('-', '_', $name));
    }

    public function provider(string $name): ?ProviderConfig
    {
        return $this->providers[$name] ?? null;
    }

    /**
     * The provider a feature whose provider setting is empty calls: the
     * only configured one, or null when there are none or several.
     */
    public function soleProvider(): ?ProviderConfig
    {
        return count($this->providers) === 1 ? array_values($this->providers)[0] : null;
    }

    /** Whether any provider is configured: without one, no AI feature can be offered. */
    public function configured(): bool
    {
        return $this->providers !== [];
    }

    private static function parseProvider(Config $config, string $name): ProviderConfig
    {
        $typeKey = self::key('TYPE', $name);
        $raw = strtolower(trim($config->string($typeKey, '')));

        if ($raw === '') {
            throw new \InvalidArgumentException("{$typeKey} is not set; every provider VECTOR_AI_PROVIDERS lists needs a type: " . self::types() . '.');
        }

        $type = ProviderType::tryFrom($raw) ?? throw new \InvalidArgumentException("{$typeKey} is \"{$raw}\", not a provider type: " . self::types() . '.');
        $key = trim($config->string(self::key('KEY', $name), ''));
        $region = trim($config->string(self::key('REGION', $name), ''));

        if ($key !== '' && !$type->takesKey()) {
            throw new \InvalidArgumentException(self::key('KEY', $name) . " is set, but a {$type->value} provider takes no API key.");
        }

        if ($key !== '' && preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            // a line break would let it add headers of its own; the message never shows it
            throw new \InvalidArgumentException(self::key('KEY', $name) . ' must be printable ASCII without spaces.');
        }

        if ($type === ProviderType::Bedrock && preg_match(self::REGION, $region) !== 1) {
            throw new \InvalidArgumentException(self::key('REGION', $name) . ' must name the AWS region of a bedrock provider, such as eu-central-1.');
        }

        return new ProviderConfig(
            $name,
            $type,
            self::baseUrl($config, $name, $type),
            $key === '' ? null : $key,
            self::list($config->string(self::key('MODELS', $name), '')),
            $type === ProviderType::Bedrock ? $region : null,
        );
    }

    /** The provider's base URL: checked, defaulted for the types with a fixed one, null for those without. */
    private static function baseUrl(Config $config, string $name, ProviderType $type): ?string
    {
        $key = self::key('BASE_URL', $name);
        $url = rtrim(trim($config->string($key, '')), '/');
        $default = $type->defaultBaseUrl();

        if (!$type->overHttp()) {
            return null;
        }

        if ($url === '') {
            return $default ?? throw new \InvalidArgumentException("{$key} is not set; a {$type->value} provider needs the base URL of its API.");
        }

        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';

        if (!is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException("{$key} is not a URL such as https://api.example.com/v1, without credentials, query or fragment.");
        }

        if ($scheme !== 'https' && !($scheme === 'http' && $type->allowsPlainHttp())) {
            throw new \InvalidArgumentException("{$key} must use https://" . ($type->allowsPlainHttp() ? ' or http://' : '') . '.');
        }

        // Neuron's Anthropic provider talks to its own API only; an OpenAI-compatible endpoint elsewhere is openai-like
        if ($type === ProviderType::Anthropic && $url !== $default) {
            throw new \InvalidArgumentException("{$key} must be {$default} for an anthropic provider, or left unset.");
        }

        return $url;
    }

    /** @return list<string> the comma-separated values, trimmed, without empty ones */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    private static function types(): string
    {
        return implode(', ', array_map(static fn (ProviderType $type): string => $type->value, ProviderType::cases()));
    }

    private static function positiveInt(Config $config, string $key, int $default): int
    {
        $raw = trim($config->string($key, ''));

        if ($raw === '') {
            return $default;
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/D', $raw) !== 1) {
            throw new \InvalidArgumentException("{$key} is \"{$raw}\", not a positive whole number.");
        }

        return (int) $raw;
    }

    private static function positiveFloat(Config $config, string $key, float $default): float
    {
        $raw = trim($config->string($key, ''));

        if ($raw === '') {
            return $default;
        }

        if (!is_numeric($raw) || (float) $raw <= 0 || (float) $raw > 3600) {
            throw new \InvalidArgumentException("{$key} is \"{$raw}\", not a number of seconds between 0 and 3600.");
        }

        return (float) $raw;
    }
}
