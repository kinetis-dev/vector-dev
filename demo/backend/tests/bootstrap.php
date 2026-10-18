<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Parallel mutation testing: Infection runs each mutant's tests in one of
 * its worker processes and names the worker in TEST_TOKEN. The tests
 * write to the seeded stores, so each worker gets stores of its own
 * (tests/worker-stores.php), provisioned beforehand by
 * bin/provision-test-workers, and a temporary directory of its own for
 * the sessions and uploads VectorTestCase keeps there. The process
 * environment wins over .env and is what a subprocess inherits, so
 * setting it here, before any test boots the application, is enough.
 * Without TEST_TOKEN (PHPUnit on its own, Infection's initial run)
 * nothing here runs.
 */
$token = getenv('TEST_TOKEN');

if (is_string($token) && $token !== '') {
    Kinetis\Config\EnvFile::safeLoad(dirname(__DIR__));

    $tmp = rtrim((string) (getenv('TMPDIR') ?: '/tmp'), '/') . "/vector-worker-{$token}";
    $settings = [...(require __DIR__ . '/worker-stores.php')($token), 'TMPDIR' => $tmp];

    foreach ($settings as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    if (!is_dir($tmp) && !mkdir($tmp, 0777, true) && !is_dir($tmp)) {
        throw new RuntimeException("Could not create the worker's temporary directory {$tmp}.");
    }

    // PHP resolves the temporary directory once per process: fail rather than share it
    if (sys_get_temp_dir() !== $tmp) {
        throw new RuntimeException("The worker's temporary directory is {$tmp}, but PHP had already resolved " . sys_get_temp_dir() . '.');
    }
}
