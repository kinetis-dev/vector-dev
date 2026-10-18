<?php

declare(strict_types=1);

/*
 * The stores one parallel mutation-testing worker uses in place of the
 * shared seeded ones: Infection numbers its worker processes 1..threads
 * in TEST_TOKEN. tests/bootstrap.php applies these settings in a worker,
 * and bin/provision-test-workers creates, migrates and seeds what they
 * name. Each name derives from the base setting, so .env must already
 * be loaded.
 *
 * Redis has 16 databases unless configured otherwise; the base cache is
 * on 1 and the jobs queue on 0, so worker N takes 2N and 2N+1, which
 * leaves room for seven workers.
 */
return static function (string $token): array {
    if (preg_match('/^[1-7]$/', $token) !== 1) {
        throw new RuntimeException("TEST_TOKEN must be a worker number from 1 to 7, got \"{$token}\".");
    }

    $worker = (int) $token;
    $suffixed = static function (string $key) use ($worker): string {
        $base = getenv($key);

        if (!is_string($base) || preg_match('/^\w+$/', $base) !== 1) {
            throw new RuntimeException("{$key} must name the base database before a worker's can be derived from it.");
        }

        return "{$base}_{$worker}";
    };

    return [
        'DB_NAME' => $suffixed('DB_NAME'),
        'DB_ADMIN_NAME' => $suffixed('DB_ADMIN_NAME'),
        'DB_SUPPORT_NAME' => $suffixed('DB_SUPPORT_NAME'),
        'REDIS_DATABASE' => (string) (2 * $worker),
        'REDIS_JOBS_DATABASE' => (string) (2 * $worker + 1),
    ];
};
