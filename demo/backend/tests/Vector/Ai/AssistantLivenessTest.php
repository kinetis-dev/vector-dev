<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Async\Timer;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Testing\LoopLiveness;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use Kinetis\VectorAi\Testing\ScriptedProvider;
use Kinetis\VectorAi\Testing\ScriptedReplies;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An assistant run lets the event loop turn while it waits on the
 * provider: a provider that waits by suspending, as one over the Kinetis
 * HTTP client does, leaves the loop turning. The same run over a provider
 * that blocks reports that it did not, which shows the proof can fail —
 * so the run has nothing else to wait on, no tool call (whose database
 * reads yield on their own, LoopLivenessTest).
 */
final class AssistantLivenessTest extends AssistantTestCase
{
    /** @return array<string, array{bool}> */
    public static function providers(): array
    {
        return ['suspending yields' => [true], 'blocking does not' => [false]];
    }

    #[DataProvider('providers')]
    public function testARunTurnsTheLoopWhileItWaits(bool $suspends): void
    {
        $replies = ScriptedReplies::of([self::QUESTION => 'products#1.']);
        $provider = new WaitingProvider($replies, $suspends);
        $scope = $this->app->createRequestScope();
        $answer = null;

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor((int) (self::ACTOR_PREFIX . '07'), 'Test', true));
            $assistant = $scope->get(Assistant::class);
            self::assertInstanceOf(Assistant::class, $assistant);

            $turned = LoopLiveness::turnedDuring(function () use ($assistant, $provider, &$answer): void {
                $answer = $assistant->answer(new ResolvedProvider($provider, 'test-fake', 'fake', 100), 'Be brief.', 'c_live', [['role' => 'user', 'content' => self::QUESTION]]);
            });
        } finally {
            $scope->dispose();
        }

        self::assertSame(['text' => 'products#1.', 'toolCalls' => []], $answer);
        self::assertSame($suspends, $turned);
    }
}

/** The scripted provider, waiting before each answer: suspending the fiber, or blocking the process. */
final class WaitingProvider extends ScriptedProvider
{
    public function __construct(ScriptedReplies $replies, private readonly bool $suspends)
    {
        parent::__construct($replies);
    }

    #[\Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        if ($this->suspends) {
            Timer::delay(0.15);
        } else {
            usleep(150_000);
        }

        return parent::chat(...$messages);
    }
}
