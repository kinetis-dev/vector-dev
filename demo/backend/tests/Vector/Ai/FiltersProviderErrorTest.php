<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use DateTimeZone;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\VectorAi\Filters\FilterTranslator;
use Kinetis\VectorAi\Providers\ResolvedProvider;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Testing\FakeAIProvider;

/**
 * Every model call is one usage row, however it ends: a provider that
 * throws something other than Neuron's own exceptions still leaves its
 * row, and what it threw propagates as it was.
 */
final class FiltersProviderErrorTest extends FiltersTestCase
{
    public function testAnUnexpectedProviderErrorStillWritesOneUsageRow(): void
    {
        $actor = self::ACTOR_PREFIX . '06';
        $scope = $this->app->createRequestScope();

        try {
            $scope->instance(CurrentUserInterface::class, new DemoActor((int) $actor, 'Test', true));
            $resources = $scope->get(ResourceService::class);
            $translator = $scope->get(FilterTranslator::class);
            self::assertInstanceOf(ResourceService::class, $resources);
            self::assertInstanceOf(FilterTranslator::class, $translator);
            $orders = $resources->resource('orders');

            try {
                $translator->translate($orders, $resources->permissions($orders), new ResolvedProvider(new ThrowingProvider(), 'test-fake', 'fake', 100), 'paid orders', new DateTimeZone('UTC'));
                self::fail('the provider\'s error did not propagate');
            } catch (\RuntimeException $e) {
                self::assertSame('The transport fell over.', $e->getMessage());
            }
        } finally {
            $scope->dispose();
        }

        $rows = $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', $actor)->get();
        self::assertCount(1, $rows);
        self::assertSame('filters', $rows[0]['feature']);
        self::assertSame('failed:error', $rows[0]['outcome']);
        self::assertSame(0, (int) $rows[0]['input_tokens']);
    }
}

/** A provider whose structured call throws a plain RuntimeException, not a Neuron exception. */
final class ThrowingProvider extends FakeAIProvider
{
    /**
     * @param Message|Message[] $messages
     * @param array<string, mixed> $response_schema
     */
    #[\Override]
    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        throw new \RuntimeException('The transport fell over.');
    }
}
