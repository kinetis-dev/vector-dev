<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Queue\QueueInterface;
use Kinetis\Queue\SyncQueue;
use Kinetis\Testing\ApplicationTestCase;

/**
 * Boots the demo application against its seeded MySQL. Tokens are
 * issued directly, so no test depends on the login endpoint, and the
 * statement counter reads MySQL's own session status on the one PDO
 * connection the test application holds.
 */
abstract class VectorTestCase extends ApplicationTestCase
{
    protected function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The suite runs as root in the container while PHP-FPM runs as
     * www-data, and the file session store keeps its directory at 0700:
     * whichever creates the directory first locks the other out. So the
     * tests keep sessions in a directory of their own.
     *
     * @return array<string, string>
     */
    protected function configOverrides(): array
    {
        $overrides = [
            'SESSION_FILES_DIR' => sys_get_temp_dir() . '/kinetis-sessions-tests',
            // uploads written by the suite (as root) stay out of the volume PHP-FPM writes as www-data
            'FILESYSTEM_ROOT' => sys_get_temp_dir() . '/vector-storage-tests',
            // Jobs run inline through the SyncQueue double below, on the
            // application's queue, as in the demo; VectorQueueTest covers
            // a named queue connection.
            'VECTOR_QUEUE_CONNECTION' => '',
        ];

        // The suite runs once per place Vector's tables can live:
        // VECTOR_TEST_DB_CONNECTION=default puts them back on the MySQL
        // default connection, whatever backend/.env says.
        $connection = getenv('VECTOR_TEST_DB_CONNECTION');

        if (is_string($connection) && $connection !== '') {
            $overrides['VECTOR_DB_CONNECTION'] = $connection;
        }

        return $overrides;
    }

    /** A builder on the connection Vector's own tables live on. */
    protected function vectorQuery(): \Kinetis\QueryBuilder\Query
    {
        $database = $this->app->get(\Kinetis\Vector\Database\VectorDatabase::class);
        self::assertInstanceOf(\Kinetis\Vector\Database\VectorDatabase::class, $database);

        return $database->query();
    }

    /** Jobs run inline, on a fresh scope per push, so a test observes their outcome synchronously. */
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        $app->instance(QueueInterface::class, new SyncQueue($app));
    }

    /** @return array<string, string> */
    protected function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->issue('1', true)];
    }

    /** @return array<string, string> */
    protected function editorHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->issue('2', false)];
    }

    /**
     * A token for the seeded admin's real id, for what must resolve the
     * user from the table: jobs, impersonation.
     *
     * @return array<string, string>
     */
    protected function realAdminHeaders(): array
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);
        $row = (new \Kinetis\QueryBuilder\Query($link))->table('users')->select('id')->where('email', '=', 'admin@vector.local')->first();
        self::assertIsArray($row);

        return ['Authorization' => 'Bearer ' . $this->issue((string) $row['id'], true)];
    }

    protected function statementsSent(): int
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        foreach ($link->query("SHOW SESSION STATUS LIKE 'Questions'") as $row) {
            return (int) $row['Value'];
        }

        self::fail('MySQL reported no Questions counter.');
    }

    /** The statements a callback sent, excluding the counter reads themselves. */
    protected function countStatements(callable $work): int
    {
        $before = $this->statementsSent();
        $work();

        return $this->statementsSent() - $before - 1;
    }

    protected function tokenFor(string $subject): string
    {
        return $this->issue($subject, true);
    }

    protected function issue(string $subject, bool $vector): string
    {
        $issuer = $this->app->get(JwtIssuer::class);
        self::assertInstanceOf(JwtIssuer::class, $issuer);

        return $issuer->issue($subject, ['name' => 'Test', 'vector' => $vector], ttlSeconds: 300);
    }
}
