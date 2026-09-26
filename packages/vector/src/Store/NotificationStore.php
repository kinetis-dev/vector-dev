<?php

declare(strict_types=1);

namespace Kinetis\Vector\Store;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\QueryBuilder\Query;

/**
 * `vector_notifications`: the inbox. Anything Vector runs on an admin's
 * behalf — a finished job, an action that wants to say something later —
 * leaves a message here; the UI polls the unread count.
 *
 * @phpstan-type NotificationRow array{id: int, kind: string, title: string, body: string|null, link: string|null, readAt: string|null, createdAt: string}
 */
final readonly class NotificationStore
{
    public function __construct(private VectorDatabase $db) {}

    /** @param 'info'|'success'|'warning'|'error' $kind */
    public function notify(string $ownerId, string $title, ?string $body = null, ?string $link = null, string $kind = 'info'): int
    {
        return (int) $this->query()->table('vector_notifications')->insertGetId([
            'owner_id' => $ownerId,
            'kind' => $kind,
            'title' => mb_substr($title, 0, 200),
            'body' => $body,
            'link' => $link,
            'read_at' => null,
            'created_at' => JobStore::now(),
        ]);
    }

    /** @return array{data: list<NotificationRow>, unread: int} */
    public function inbox(string $ownerId, bool $unreadOnly = false, int $limit = 50): array
    {
        $query = $this->query()->table('vector_notifications')->where('owner_id', '=', $ownerId);

        if ($unreadOnly) {
            $query->where('read_at', '=', null);
        }

        $rows = $query->orderBy('id', 'DESC')->limit($limit)->get();
        $unread = $this->query()->table('vector_notifications')->where('owner_id', '=', $ownerId)->where('read_at', '=', null)->count();

        return ['data' => array_map(self::row(...), $rows), 'unread' => $unread];
    }

    public function markRead(string $ownerId, ?int $id): int
    {
        $query = $this->query()->table('vector_notifications')->where('owner_id', '=', $ownerId)->where('read_at', '=', null);

        if ($id !== null) {
            $query->where('id', '=', $id);
        }

        return $query->update(['read_at' => JobStore::now()]);
    }

    /**
     * @param array<string, mixed> $row
     * @return NotificationRow
     */
    private static function row(array $row): array
    {
        $iso = static fn (string $v): string => (new DateTimeImmutable($v, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');

        return [
            'id' => (int) $row['id'],
            'kind' => (string) $row['kind'],
            'title' => (string) $row['title'],
            'body' => $row['body'] === null ? null : (string) $row['body'],
            'link' => $row['link'] === null ? null : (string) $row['link'],
            'readAt' => $row['read_at'] === null ? null : $iso((string) $row['read_at']),
            'createdAt' => $iso((string) $row['created_at']),
        ];
    }

    private function query(): Query
    {
        return $this->db->query();
    }
}
