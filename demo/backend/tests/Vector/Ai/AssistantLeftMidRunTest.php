<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Providers\ProviderResolver;

/**
 * Once the browser has left, the run is read no further: the model is
 * not called again, no tool runs and nothing is proposed for nobody to
 * see. The browser here leaves as soon as it has been sent the first
 * tool's result; the run would have gone on to propose an update.
 */
final class AssistantLeftMidRunTest extends ApprovalsTestCase
{
    public function testNoFurtherModelCallToolOrProposalOnceTheBrowserLeft(): void
    {
        $this->script([self::QUESTION => self::rounds([
            [['name' => 'vector_resources']],
            [['name' => 'vector_update', 'arguments' => $this->update($this->stock + 5)]],
        ], 'Never answered.')]);

        $written = '';
        $connection = new LeavesOnceItSaw(static function () use (&$written): bool {
            return str_contains($written, 'tool-output-available');
        });
        $scope = $this->app->createRequestScope();

        try {
            $user = new DemoActor((int) (self::ACTOR_PREFIX . '01'), 'Test', true);
            $scope->instance(CurrentUserInterface::class, $user);
            $assistant = $scope->get(Assistant::class);
            $resolver = $scope->get(ProviderResolver::class);
            self::assertInstanceOf(Assistant::class, $assistant);
            self::assertInstanceOf(ProviderResolver::class, $resolver);
            self::assertTrue($assistant->writes(), 'the run may propose');

            $response = $assistant->stream($resolver->for(Feature::Assistant, $user), $connection, 'Be brief.', 'c_left', [['role' => 'user', 'content' => self::QUESTION]]);
            ob_start(static function (string $chunk) use (&$written): string {
                $written .= $chunk;

                return '';
            });

            try {
                ($response->getEmitter())();
            } finally {
                ob_end_flush();
            }
        } finally {
            $scope->dispose();
        }

        self::assertStringContainsString('tool-output-available', $written, 'the browser saw the first tool\'s result');
        self::assertCount(1, $this->calls(), 'the model was not called again after the browser left');
        self::assertSame([], $this->rows(), 'nothing was proposed for nobody to see');
        self::assertStringNotContainsString('data-approval', $written);
        self::assertSame($this->stock, $this->stockNow());
    }
}
