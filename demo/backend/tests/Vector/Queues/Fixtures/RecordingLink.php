<?php

declare(strict_types=1);

namespace App\Tests\Vector\Queues\Fixtures;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\MysqlTransaction;
use Kinetis\Persistence\Contract\SqlResult;

/** A MySQL link that counts every statement sent to it and refuses each one with $failure: a database that is down. */
final class RecordingLink implements MysqlLink
{
    /** @var list<string> */
    public array $statements = [];

    public function __construct(private readonly \Throwable $failure = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused')) {}

    public function query(string $sql): SqlResult
    {
        $this->statements[] = $sql;

        throw $this->failure;
    }

    public function execute(string $sql, array $params = []): SqlResult
    {
        $this->statements[] = $sql;

        throw $this->failure;
    }

    public function beginTransaction(): MysqlTransaction
    {
        $this->statements[] = 'START TRANSACTION';

        throw $this->failure;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }
}
