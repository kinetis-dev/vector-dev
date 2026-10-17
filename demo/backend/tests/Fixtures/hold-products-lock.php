<?php

declare(strict_types=1);

/*
 * LoopLivenessTest's slow database: a connection of its own takes a write
 * lock on `products`, which keeps every other session's reads and writes
 * of the table waiting, says so on STDOUT, holds it for HOLD_MS and lets
 * go. The connection settings arrive in the environment.
 */

$host = (string) getenv('LOCK_DB_HOST');
$port = (string) getenv('LOCK_DB_PORT');
$name = (string) getenv('LOCK_DB_NAME');
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name}", (string) getenv('LOCK_DB_USER'), (string) getenv('LOCK_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('LOCK TABLES products WRITE');
fwrite(STDOUT, "locked\n");
fflush(STDOUT);

usleep((int) getenv('LOCK_HOLD_MS') * 1000);

$pdo->exec('UNLOCK TABLES');
