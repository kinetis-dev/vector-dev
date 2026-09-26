<?php

declare(strict_types=1);

namespace App\Auth;

use Kinetis\Http\CurrentUserInterface;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\Persistence\Contract\PostgresLink;
use Kinetis\Persistence\Contract\SqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Auth\ActorResolverInterface;

/** Rebuilds a DemoActor from the users table for a background job. Bound on AppScope; holds the link only. */
final readonly class DemoActorResolver implements ActorResolverInterface
{
    public function __construct(private SqlLink $db) {}

    public function resolve(string $actorId): ?CurrentUserInterface
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $actorId) !== 1) {
            return null;
        }

        $link = $this->db instanceof MysqlLink || $this->db instanceof PostgresLink ? $this->db : throw new \LogicException('The bound SqlLink is neither a MysqlLink nor a PostgresLink.');
        $row = (new Query($link))->table('users')->select('id', 'name', 'is_admin')->where('id', '=', (int) $actorId)->first();

        return is_array($row) ? new DemoActor((int) $row['id'], (string) $row['name'], (int) $row['is_admin'] === 1) : null;
    }
}
