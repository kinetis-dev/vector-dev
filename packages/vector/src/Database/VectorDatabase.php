<?php

declare(strict_types=1);

namespace Kinetis\Vector\Database;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\DatabaseBridge\ConnectionFactory;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\QueryBuilder\Query;

/**
 * The connection Vector's own tables live on — `vector_views`,
 * `vector_jobs`, `vector_audit` and the rest — which may differ from the
 * connection the administered entities use. `VECTOR_DB_CONNECTION` names
 * it; unset, it is the default connection. MySQL and PostgreSQL are both
 * supported on either.
 *
 * One instance per worker, bound on AppScope. The link is resolved on
 * first use: the default connection's `SqlLink`, the application's own
 * `db.<name>` binding when `bootstrap.php` made one, or else a link this
 * class builds from the connection's `DB_<NAME>_*` keys and closes with
 * the application scope. It holds that link and nothing request-scoped.
 */
final class VectorDatabase
{
    /** kinetis/orm's and kinetis/migrations' connection-name grammar. */
    private const string NAME = '/^[a-z][a-z0-9]*$/D';

    private MysqlLink|PostgresLink|null $link = null;

    private function __construct(
        private readonly AppScope $app,
        private readonly Config $config,
        public readonly string $connection,
    ) {}

    public static function fromConfig(AppScope $app, Config $config): self
    {
        $connection = trim($config->string('VECTOR_DB_CONNECTION', 'default'));

        return new self($app, $config, self::validated($connection === '' ? 'default' : $connection, 'VECTOR_DB_CONNECTION'));
    }

    /**
     * A connection name as kinetis/migrations and kinetis/orm accept it.
     *
     * @throws \InvalidArgumentException
     */
    public static function validated(string $connection, string $source): string
    {
        if ($connection === 'default') {
            return $connection;
        }

        if (preg_match(self::NAME, $connection) !== 1) {
            throw new \InvalidArgumentException("Invalid connection name \"{$connection}\" from {$source}: a connection name is lowercase ASCII letters and digits, starting with a letter.");
        }

        if ($connection === 'app') {
            throw new \InvalidArgumentException("The connection name \"app\" from {$source} is reserved: its DB_NAME key, DB_APP_NAME, is also the default connection's DB_APP_NAME.");
        }

        return $connection;
    }

    public function link(): MysqlLink|PostgresLink
    {
        return $this->link ??= $this->resolve();
    }

    /** A fresh builder on Vector's connection, one per statement. */
    public function query(): Query
    {
        return new Query($this->link());
    }

    public function isPostgres(): bool
    {
        return $this->link() instanceof PostgresLink;
    }

    private function resolve(): MysqlLink|PostgresLink
    {
        if ($this->connection === 'default') {
            $link = $this->app->get(SqlLink::class);

            return $link instanceof MysqlLink || $link instanceof PostgresLink
                ? $link
                : throw new \LogicException('The default SqlLink is neither a MysqlLink nor a PostgresLink; set DB_CONNECTION.');
        }

        $id = "db.{$this->connection}";

        if ($this->app->has($id)) {
            $link = $this->app->get($id);

            return $link instanceof MysqlLink || $link instanceof PostgresLink
                ? $link
                : throw new \LogicException("\"{$id}\" is bound to " . get_debug_type($link) . '; Vector needs a MysqlLink or PostgresLink.');
        }

        $key = Config::scopedKey('DB_CONNECTION', $this->connection);

        if ($this->config->get($key) === null) {
            throw new \LogicException("VECTOR_DB_CONNECTION names the connection \"{$this->connection}\", but {$key} is not set.");
        }

        $link = ConnectionFactory::fromConfig($this->config, $this->connection);
        $this->app->onDispose($link->close(...));

        return $link;
    }
}
