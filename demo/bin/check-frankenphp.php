<?php

declare(strict_types=1);

/*
 * The FrankenPHP runtime check; run it through bin/check-frankenphp,
 * which runs this in the app container against the frankenphp service.
 *
 *   (a) worker mode: GET /_demo/runtime (development only) reports the
 *       boot that answered and how many requests that boot has served.
 *       Every boot of a worker thread serves request after request, so
 *       far fewer boots than requests answer, with counts climbing;
 *       classic mode boots once per request and always reports 1.
 *   (b) the native drivers: the same document names the database
 *       clients DB_DRIVER=auto built and the event-loop driver.
 *   (c) request isolation: four principals — the seeded admin (#1), the
 *       end-to-end admin (#3), the editor (no admin access) and
 *       anonymous — interleaved at random, many requests in flight at
 *       once, round after round, on the same worker threads. Every
 *       response must be its own principal's: the identity, the admin
 *       access, and the per-record permission overlay (nobody may
 *       delete their own account, so #1 and #3 see opposite `delete`
 *       permissions on users 1 and 3).
 *
 * Options: --base=URL (default http://frankenphp:8081), --rounds=N
 * (default 25), --concurrency=N (default 24), --threads=N (the worker
 * thread count to expect at most, default 12), --wrong-expectation
 * (expects the admin's own record to be deletable by the admin, which
 * must make (c) fail — proof the check can fail).
 */

$options = getopt('', ['base:', 'rounds:', 'concurrency:', 'threads:', 'wrong-expectation']);
$base = rtrim((string) ($options['base'] ?? 'http://frankenphp:8081'), '/');
$rounds = max(1, (int) ($options['rounds'] ?? 25));
$concurrency = max(1, (int) ($options['concurrency'] ?? 24));
$threads = max(1, (int) ($options['threads'] ?? 12));
$wrongExpectation = array_key_exists('wrong-expectation', $options);

$failures = 0;

function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures++;
    }

    echo ($ok ? '  ok    ' : '  FAIL  ') . $label . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Sends every request at once, at most $concurrency in flight.
 *
 * @param list<array{method: string, path: string, token: ?string, body?: string}> $requests
 * @return list<array{status: int, body: string}>
 */
function send(string $base, array $requests, int $concurrency): array
{
    $multi = curl_multi_init();
    curl_multi_setopt($multi, CURLMOPT_MAX_TOTAL_CONNECTIONS, $concurrency);
    $handles = [];

    foreach ($requests as $i => $request) {
        $handle = curl_init($base . $request['path']);
        $headers = ['Accept: application/json'];

        if ($request['token'] !== null) {
            $headers[] = 'Authorization: Bearer ' . $request['token'];
        }

        if (isset($request['body'])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request['body']);
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $request['method'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$i] = $handle;
    }

    do {
        $status = curl_multi_exec($multi, $running);

        if ($running > 0) {
            curl_multi_select($multi, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $responses = [];

    foreach ($handles as $i => $handle) {
        $responses[$i] = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => (string) curl_multi_getcontent($handle),
        ];
        curl_multi_remove_handle($multi, $handle);
    }

    curl_multi_close($multi);

    return $responses;
}

/** @return array<string, mixed>|null */
function json(string $body): ?array
{
    $decoded = json_decode($body, true);

    return is_array($decoded) ? $decoded : null;
}

echo "FrankenPHP runtime check against {$base}\n";

// --- (a) and (b) -----------------------------------------------------------

echo "\n(a) worker mode, (b) native drivers\n";

$probes = send($base, array_fill(0, $threads * 8, ['method' => 'GET', 'path' => '/_demo/runtime', 'token' => null]), $concurrency);
$documents = array_map(static fn (array $r): ?array => $r['status'] === 200 ? json($r['body']) : null, $probes);
$answered = array_values(array_filter($documents));

check(count($answered) === count($probes), 'GET /_demo/runtime answers', count($answered) . ' of ' . count($probes) . ' with 200');

if ($answered !== []) {
    $sapis = array_unique(array_map(static fn (array $d): string => (string) $d['sapi'], $answered));
    check($sapis === ['frankenphp'], 'served by the FrankenPHP SAPI', implode(', ', $sapis));

    /** @var array<string, list<int>> $boots */
    $boots = [];

    foreach ($answered as $document) {
        $boots[(string) $document['boot']['id']][] = (int) $document['boot']['requestsServed'];
    }

    $climbing = true;

    foreach ($boots as $counts) {
        sort($counts);
        $climbing = $climbing && count(array_unique($counts)) === count($counts);
    }

    $maxServed = max(array_map('max', $boots));
    check(
        count($boots) <= $threads && $maxServed > 1 && $climbing,
        'requests are served by persistent worker boots, not classic boot-per-request',
        sprintf('%d requests answered by %d boots (at most %d worker threads), the busiest at its request #%d', count($answered), count($boots), $threads, $maxServed),
    );

    $drivers = array_unique(array_map(static fn (array $d): string => json_encode($d['database']), $answered));
    $database = $answered[0]['database'];
    check(
        count($drivers) === 1
            && ($database['default'] ?? null) === 'Kinetis\Persistence\Driver\MysqliAsyncClient'
            && ($database['admin'] ?? null) === 'Kinetis\Persistence\Driver\PgsqlAsyncClient',
        'DB_DRIVER=auto selected the native non-blocking drivers',
        'default: ' . ($database['default'] ?? '?') . ', admin: ' . ($database['admin'] ?? '?'),
    );

    $loop = (string) $answered[0]['eventLoop'];
    check($loop !== 'Revolt\EventLoop\Driver\StreamSelectDriver', 'the event loop is not the select() driver', $loop);
}

// --- (c) ------------------------------------------------------------------

echo "\n(c) request isolation, {$rounds} rounds\n";

$tokens = [];

foreach (['admin' => 'admin@vector.local', 'e2e' => 'e2e@vector.local', 'editor' => 'editor@vector.local'] as $name => $email) {
    $login = send($base, [['method' => 'POST', 'path' => '/auth/login', 'token' => null, 'body' => json_encode(['email' => $email, 'password' => 'password'])]], 1)[0];
    $token = json($login['body'])['accessToken'] ?? null;
    check(is_string($token), "signs in as {$email}", "HTTP {$login['status']}");
    $tokens[$name] = is_string($token) ? $token : null;
}

/**
 * What each principal must see, by path: the status and, for a 200, the
 * fields of the document that identify whose response it is.
 *
 * @var array<string, array{token: ?string, expect: array<string, array{0: int, 1: array<string, mixed>}>}> $principals
 */
$principals = [
    'admin' => ['token' => $tokens['admin'], 'expect' => [
        '/vector/me' => [200, ['id' => 1, 'name' => 'Vector Admin', 'impersonator' => null]],
        '/vector/r/users/1' => [200, ['data.id' => 1, 'permissions.delete' => $wrongExpectation]],
        '/vector/r/users/3' => [200, ['data.id' => 3, 'permissions.delete' => true]],
        '/auth/me' => [200, ['id' => '1', 'vector' => true]],
    ]],
    'e2e' => ['token' => $tokens['e2e'], 'expect' => [
        '/vector/me' => [200, ['id' => 3, 'name' => 'E2E Admin', 'impersonator' => null]],
        '/vector/r/users/1' => [200, ['data.id' => 1, 'permissions.delete' => true]],
        '/vector/r/users/3' => [200, ['data.id' => 3, 'permissions.delete' => false]],
        '/auth/me' => [200, ['id' => '3', 'vector' => true]],
    ]],
    'editor' => ['token' => $tokens['editor'], 'expect' => [
        '/vector/me' => [403, []],
        '/vector/r/users/1' => [403, []],
        '/vector/r/users/3' => [403, []],
        '/auth/me' => [200, ['id' => '2', 'name' => 'Demo Editor', 'vector' => false]],
    ]],
    'anonymous' => ['token' => null, 'expect' => [
        '/vector/me' => [401, []],
        '/vector/r/users/1' => [401, []],
        '/vector/r/users/3' => [401, []],
        '/auth/me' => [401, []],
    ]],
];

function field(array $document, string $path): mixed
{
    foreach (explode('.', $path) as $key) {
        if (!is_array($document) || !array_key_exists($key, $document)) {
            return '(absent)';
        }

        $document = $document[$key];
    }

    return $document;
}

mt_srand(20261017);
$sent = 0;
$wrong = [];
/** @var array<string, true> $isolationBoots */
$isolationBoots = [];

for ($round = 1; $round <= $rounds; $round++) {
    $batch = [];

    foreach ($principals as $name => $principal) {
        foreach (array_keys($principal['expect']) as $path) {
            $batch[] = ['principal' => $name, 'method' => 'GET', 'path' => $path, 'token' => $principal['token']];
        }
    }

    // probes in the same batch show which boots — which threads — served the round
    for ($i = 0; $i < 4; $i++) {
        $batch[] = ['principal' => null, 'method' => 'GET', 'path' => '/_demo/runtime', 'token' => null];
    }

    shuffle($batch);
    $responses = send($base, $batch, $concurrency);

    foreach ($batch as $i => $request) {
        $response = $responses[$i];

        if ($request['principal'] === null) {
            $id = json($response['body'])['boot']['id'] ?? null;

            if (is_string($id)) {
                $isolationBoots[$id] = true;
            }

            continue;
        }

        $sent++;
        [$status, $fields] = $principals[$request['principal']]['expect'][$request['path']];
        $document = json($response['body']) ?? [];
        $mismatch = [];

        if ($response['status'] !== $status) {
            $mismatch[] = "HTTP {$response['status']}, expected {$status}";
        }

        foreach ($fields as $path => $expected) {
            $actual = field($document, $path);

            if ($actual !== $expected) {
                $mismatch[] = "{$path} = " . json_encode($actual) . ', expected ' . json_encode($expected);
            }
        }

        if ($mismatch !== []) {
            $wrong[] = "round {$round}, {$request['principal']} GET {$request['path']}: " . implode('; ', $mismatch);
        }
    }
}

check($wrong === [], 'every response reflects only its own principal', sprintf('%d responses, %d wrong, across %d worker boots', $sent, count($wrong), count($isolationBoots)));

foreach (array_slice($wrong, 0, 10) as $line) {
    echo "        {$line}\n";
}

if (count($wrong) > 10) {
    echo '        … and ' . (count($wrong) - 10) . " more\n";
}

echo $failures === 0 ? "\nPASS\n" : "\nFAIL: {$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
