<?php

declare(strict_types=1);

namespace Kinetis\Vector\Database;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;

/**
 * The SQL dialect of each connection an administered entity lives on,
 * for the few statements Vector writes by hand (search, the `contains`
 * filter, summaries). Read from the link the application bound for the
 * connection — the default `SqlLink`, or a `db.<name>` binding — and
 * otherwise from the connection's `DB_CONNECTION` / `DB_<NAME>_CONNECTION`
 * key, the same key the bridge builds that connection's link from.
 *
 * One instance per worker; the memo is keyed by connection name and
 * never holds request state.
 */
final class Connections
{
    public const string MYSQL = 'mysql';
    public const string PGSQL = 'pgsql';

    /** @var array<string, 'mysql'|'pgsql'> */
    private array $dialects = [];

    public function __construct(
        private readonly AppScope $app,
        private readonly Config $config,
    ) {}

    /** @return 'mysql'|'pgsql' */
    public function dialect(string $connection): string
    {
        return $this->dialects[$connection] ??= $this->resolve($connection);
    }

    public function isPostgres(string $connection): bool
    {
        return $this->dialect($connection) === self::PGSQL;
    }

    /** @return 'mysql'|'pgsql' */
    private function resolve(string $connection): string
    {
        $id = $connection === 'default' ? SqlLink::class : "db.{$connection}";

        if ($this->app->has($id)) {
            return $this->app->get($id) instanceof PostgresLink ? self::PGSQL : self::MYSQL;
        }

        return $this->config->get(Config::scopedKey('DB_CONNECTION', $connection)) === self::PGSQL ? self::PGSQL : self::MYSQL;
    }
}
