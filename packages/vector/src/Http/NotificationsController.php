<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Store\NotificationStore;
use Psr\Http\Message\ServerRequestInterface;

/** The inbox. */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class NotificationsController
{
    public function __construct(
        private NotificationStore $notifications,
        private CurrentUserInterface $user,
    ) {}

    /** @return array{data: list<array<string, mixed>>, unread: int} */
    #[Get('/notifications')]
    public function index(ServerRequestInterface $request): array
    {
        $unread = in_array($request->getQueryParams()['unread'] ?? null, ['1', 'true'], true);

        return $this->notifications->inbox((string) $this->user->id(), $unread);
    }

    /** @return array{read: int} */
    #[Post('/notifications/read-all')]
    public function readAll(): array
    {
        return ['read' => $this->notifications->markRead((string) $this->user->id(), null)];
    }

    /** @return array{read: int} */
    #[Post('/notifications/{id}/read')]
    public function read(string $id): array
    {
        if (preg_match('/^[1-9]\d{0,17}$/D', $id) !== 1) {
            throw RecordNotFoundException::of('notifications', $id);
        }

        return ['read' => $this->notifications->markRead((string) $this->user->id(), (int) $id)];
    }
}
