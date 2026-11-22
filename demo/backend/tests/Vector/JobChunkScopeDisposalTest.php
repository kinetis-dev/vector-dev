<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use App\Tests\Vector\Queues\Fixtures\RecordingLogger;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Jobs\JobContext;
use Psr\Log\LoggerInterface;

/**
 * A queued action's chunk scope (JobContext::inFreshScope()) is disposed
 * of as kinetis/queue's SyncQueue disposes of a job's: the chunk's own
 * failure is what the job fails with, and a disposal failure on top of it
 * is logged rather than put in its place; alone, it is the outcome.
 */
final class JobChunkScopeDisposalTest extends VectorTestCase
{
    public function testTheChunksOwnFailureWinsAndTheDisposalFailureIsLogged(): void
    {
        $logger = new RecordingLogger();
        [$context, $scope] = $this->context($logger);

        $caught = null;

        try {
            $context->inFreshScope(static function (JobContext $fresh): never {
                $chunk = $fresh->get(RequestScope::class);
                self::assertInstanceOf(RequestScope::class, $chunk);
                $chunk->onDispose(static function (): void {
                    throw new \RuntimeException('the chunk scope could not be released');
                });

                throw new \DomainException('the chunk failed');
            });
        } catch (\DomainException $e) {
            $caught = $e;
        } finally {
            $scope->dispose();
        }

        self::assertSame('the chunk failed', $caught->getMessage(), 'the chunk\'s own failure was rethrown, not the disposal\'s');

        $errors = $logger->at('error');
        self::assertCount(1, $errors);
        self::assertStringContainsString('the chunk scope could not be released', $errors[0]['message']);
    }

    public function testADisposalFailureAloneIsTheOutcome(): void
    {
        [$context, $scope] = $this->context(new RecordingLogger());

        try {
            $this->expectExceptionMessage('the chunk scope could not be released');
            $context->inFreshScope(static function (JobContext $fresh): string {
                $chunk = $fresh->get(RequestScope::class);
                self::assertInstanceOf(RequestScope::class, $chunk);
                $chunk->onDispose(static function (): void {
                    throw new \RuntimeException('the chunk scope could not be released');
                });

                return 'done';
            });
        } finally {
            $scope->dispose();
        }
    }

    /** @return array{JobContext, RequestScope} the job's context, on a job scope whose actor is registered and whose logger is $logger */
    private function context(LoggerInterface $logger): array
    {
        $scope = $this->app->createRequestScope();
        $user = new DemoActor(1, 'Admin', true);
        $scope->instance(CurrentUserInterface::class, $user);
        $scope->instance(ActorContext::class, new ActorContext($user));
        $scope->instance(LoggerInterface::class, $logger);
        $context = $scope->get(JobContext::class);
        self::assertInstanceOf(JobContext::class, $context);

        return [$context, $scope];
    }
}
