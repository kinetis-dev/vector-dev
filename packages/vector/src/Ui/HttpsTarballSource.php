<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * An npm registry over HTTPS, through PHP's own stream wrapper: TLS
 * verified, every URL and every redirect https, connect and read
 * timeouts, and a byte cap on every body. Metadata comes from the
 * abbreviated packument (`Accept: application/vnd.npm.install-v1+json`).
 *
 * `VECTOR_UI_REGISTRY_TOKEN`, when set, is sent as a bearer token to the
 * registry's own host and to no other: a tarball the registry serves from
 * another host is fetched without it. The token never appears in a URL,
 * an error or the output.
 *
 * Blocking I/O, deliberately: this runs in `vector:install-ui`, a
 * short-lived console command, never in a worker.
 */
final readonly class HttpsTarballSource implements UiTarballSource
{
    public const string DEFAULT_REGISTRY = 'https://registry.npmjs.org';

    private const int MAX_METADATA = 16 * 1024 * 1024;

    private const int MAX_REDIRECTS = 5;

    private const int TIMEOUT = 30;

    private string $registry;

    private string $registryHost;

    /** @var \Closure(string, list<string>): array{int, array<string, string>, resource} */
    private \Closure $open;

    /**
     * @param ?\Closure(string, list<string>): array{int, array<string, string>, resource} $open
     *     the transport: GETs a URL with the given request headers and
     *     answers its status, its response headers (lower-case names) and
     *     its body as a stream; PHP's https wrapper unless a test gives one
     *
     * @throws \InvalidArgumentException when the registry is not a plain https URL, or the token
     *     is not one header value
     */
    public function __construct(
        string $registry,
        #[\SensitiveParameter]
        private ?string $token,
        ?\Closure $open = null,
    ) {
        $this->registry = rtrim($registry, '/');
        $this->registryHost = self::origin($this->registry) ?? throw new \InvalidArgumentException('VECTOR_UI_REGISTRY must be an https URL without credentials, such as ' . self::DEFAULT_REGISTRY . '.');
        $this->open = $open ?? self::streamOpen(...);

        if ($token !== null && preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            // a line break would let it add headers of its own; the message never shows it
            throw new \InvalidArgumentException('VECTOR_UI_REGISTRY_TOKEN must be printable ASCII without spaces.');
        }
    }

    /**
     * The `scheme://host:port` of an https URL, lower-cased, or null when
     * $url is not https or carries credentials, which an error message
     * could otherwise print.
     */
    public static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (
            !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        return 'https://' . strtolower($parts['host']) . ':' . ($parts['port'] ?? 443);
    }

    #[\Override]
    public function resolve(string $package, string $version): array
    {
        $url = $this->registry . '/' . str_replace('/', '%2F', $package);
        $sink = fopen('php://temp', 'w+b');

        if ($sink === false) {
            throw new \RuntimeException('Could not buffer the registry answer.');
        }

        try {
            $this->fetch($url, ['Accept: application/vnd.npm.install-v1+json'], $sink, self::MAX_METADATA);
            rewind($sink);
            $body = (string) stream_get_contents($sink);
        } finally {
            fclose($sink);
        }

        try {
            $packument = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException("The registry's answer for {$package} is not JSON.");
        }

        $dist = is_array($packument) && is_array($packument['versions'] ?? null) && is_array($packument['versions'][$version] ?? null)
            ? ($packument['versions'][$version]['dist'] ?? null)
            : null;

        if (!is_array($dist) || !is_string($dist['tarball'] ?? null)) {
            throw new \RuntimeException("The registry publishes no {$package}@{$version}.");
        }

        return ['tarball' => $dist['tarball'], 'integrity' => is_string($dist['integrity'] ?? null) ? $dist['integrity'] : null];
    }

    #[\Override]
    public function download(string $url, string $path, int $maxBytes): void
    {
        $sink = @fopen($path, 'xb');

        if ($sink === false) {
            throw new \RuntimeException("Could not create {$path}.");
        }

        try {
            $this->fetch($url, ['Accept: application/octet-stream'], $sink, $maxBytes);
        } finally {
            fclose($sink);
        }
    }

    /**
     * GETs $url into $sink, following at most MAX_REDIRECTS redirects,
     * each to https, and refusing a body over $maxBytes.
     *
     * @param list<string> $headers
     * @param resource $sink
     */
    private function fetch(string $url, array $headers, $sink, int $maxBytes): void
    {
        for ($redirects = 0; ; $redirects++) {
            $origin = self::origin($url) ?? throw new \RuntimeException("Refusing {$this->shown($url)}: only https URLs are fetched.");
            $sent = [...$headers, 'User-Agent: kinetis-vector-install-ui', 'Connection: close'];

            if ($this->token !== null && $this->token !== '' && $origin === $this->registryHost) {
                $sent[] = 'Authorization: Bearer ' . $this->token;
            }

            [$status, $responseHeaders, $body] = ($this->open)($url, $sent);

            try {
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    if ($redirects >= self::MAX_REDIRECTS) {
                        throw new \RuntimeException("{$this->shown($url)} redirects more than " . self::MAX_REDIRECTS . ' times.');
                    }

                    $location = $responseHeaders['location'] ?? throw new \RuntimeException("{$this->shown($url)} redirects without a Location.");
                    // a path is on the same origin; anything else must name its own, and pass origin() above
                    $url = str_starts_with($location, '/') && !str_starts_with($location, '//') ? $origin . $location : $location;

                    continue;
                }

                if ($status !== 200) {
                    throw new \RuntimeException("{$this->shown($url)} answered HTTP {$status}.");
                }

                $copied = stream_copy_to_stream($body, $sink, $maxBytes + 1);

                if ($copied === false || !feof($body) && $copied <= $maxBytes) {
                    throw new \RuntimeException("Could not read {$this->shown($url)} whole.");
                }

                if ($copied > $maxBytes) {
                    throw new \RuntimeException("{$this->shown($url)} is larger than {$maxBytes} bytes.");
                }

                return;
            } finally {
                fclose($body);
            }
        }
    }

    /** A URL as a message may show it: without a query, which a signed URL could carry a secret in. */
    private function shown(string $url): string
    {
        $query = strpos($url, '?');

        return json_encode($query === false ? $url : substr($url, 0, $query), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '"?"';
    }

    /**
     * PHP's https wrapper: peer and name verified, no redirect followed
     * (fetch() follows them itself, https only), timeouts on connect and
     * on every read.
     *
     * @param list<string> $headers the Authorization header among them, so never in a stack trace
     * @return array{int, array<string, string>, resource}
     */
    private static function streamOpen(string $url, #[\SensitiveParameter] array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => self::TIMEOUT,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);

        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            $host = parse_url($url, PHP_URL_HOST);

            throw new \RuntimeException('Could not connect to ' . (is_string($host) ? $host : 'the registry') . ' over verified TLS.');
        }

        stream_set_timeout($stream, self::TIMEOUT);
        $status = 0;
        $responseHeaders = [];
        $wrapper = stream_get_meta_data($stream)['wrapper_data'] ?? [];

        foreach (is_array($wrapper) ? $wrapper : [] as $line) {
            if (!is_string($line)) {
                continue;
            }

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                // the last status line is the answer's
                $status = (int) $match[1];
                $responseHeaders = [];
            } elseif (($colon = strpos($line, ':')) !== false) {
                $responseHeaders[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
            }
        }

        return [$status, $responseHeaders, $stream];
    }
}
