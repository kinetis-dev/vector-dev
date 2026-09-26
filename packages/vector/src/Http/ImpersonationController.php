<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Auth\ImpersonatedUserInterface;
use Kinetis\Vector\Auth\ImpersonationInterface;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;

/**
 * Acting as another admin. Vector decides who may (the application's
 * ImpersonationInterface), logs the start and the stop, and hands the
 * credential change back to the client; the application performs it.
 */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class ImpersonationController
{
    public function __construct(
        private RequestScope $scope,
        private CurrentUserInterface $user,
        private VectorDatabase $vector,
    ) {}

    /** @return array<string, mixed> */
    #[Post('/impersonate/{userId}')]
    public function start(string $userId): array
    {
        $service = $this->service();

        if ($this->user instanceof ImpersonatedUserInterface && $this->user->impersonatorId() !== null) {
            throw new ForbiddenOperationException('Stop the current impersonation first.');
        }

        if (!$service->canImpersonate($this->user)) {
            throw new ForbiddenOperationException('This account may not impersonate.');
        }

        if ((string) $this->user->id() === $userId) {
            throw new ForbiddenOperationException('You are already yourself.');
        }

        $target = $service->describe($userId) ?? throw RecordNotFoundException::of('users', $userId);
        $result = $service->start($this->user, $userId);
        $this->log('impersonate:start', $userId, (string) $this->user->id());

        return [...$result, 'target' => $target, 'impersonator' => $this->user->id()];
    }

    /** @return array<string, mixed> */
    #[Delete('/impersonate')]
    public function stop(): array
    {
        $impersonator = $this->user instanceof ImpersonatedUserInterface ? $this->user->impersonatorId() : null;

        if ($impersonator === null) {
            throw new ForbiddenOperationException('Nobody is being impersonated.');
        }

        $result = $this->service()->stop($this->user);
        $this->log('impersonate:stop', (string) $this->user->id(), (string) $impersonator);

        return $result;
    }

    private function service(): ImpersonationInterface
    {
        if (!$this->scope->has(ImpersonationInterface::class)) {
            throw new ForbiddenOperationException('This application does not support impersonation.');
        }

        $service = $this->scope->get(ImpersonationInterface::class);

        return $service instanceof ImpersonationInterface ? $service : throw new \LogicException('ImpersonationInterface did not resolve.');
    }

    private function log(string $action, string $targetId, string $actorId): void
    {
        $this->vector->query()->table('vector_action_events')->insert([
            'actor_id' => $actorId,
            'impersonator_id' => null,
            'resource' => 'users',
            'action' => $action,
            'record_ids' => json_encode([$targetId], JSON_THROW_ON_ERROR),
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
        ]);
    }
}
