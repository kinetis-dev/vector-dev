<?php

declare(strict_types=1);

namespace Kinetis\Vector\Auth;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Logging\SafeLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Runs work on a request scope of its own, acting as a given admin: the
 * scope is created as the worker creates a job's (AppScope::createRequestScope(),
 * every request-scope initializer included), the actor is registered on it
 * as CurrentUserInterface and ActorContext, so every request-scoped service
 * resolved there answers for that admin, and it is disposed of after as
 * kinetis/queue's SyncQueue disposes of a job's scope: the work's own
 * failure is rethrown, and a disposal failure on top of it is logged at
 * error rather than put in its place; alone, a disposal failure is the
 * outcome. What each chunk of a queued action runs in
 * (JobContext::inFreshScope()), and what a shell contributor answering for
 * another user than the request's can build their answer in.
 */
final readonly class ActorScope
{
    public function __construct(
        private AppScope $app,
        private LoggerInterface $log,
    ) {}

    /**
     * @template T
     * @param \Closure(RequestScope): T $body
     * @return T
     */
    public function run(ActorContext $actor, \Closure $body): mixed
    {
        $scope = $this->app->createRequestScope();
        $failure = null;
        $result = null;

        try {
            $scope->instance(CurrentUserInterface::class, $actor->user);
            $scope->instance(ActorContext::class, $actor);
            $result = $body($scope);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        try {
            $scope->dispose();
        } catch (\Throwable $disposal) {
            if ($failure === null) {
                throw $disposal;
            }

            SafeLogger::log($this->log, LogLevel::ERROR, 'Disposing of a request scope run as admin ' . $actor->actorId() . ' failed after its own work had failed: ' . $disposal->getMessage(), ['exception' => $disposal]);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }
}
