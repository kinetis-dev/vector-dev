<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Shell\AiShellContributor;

/**
 * With a provider configured, a model resolvable and the default switches,
 * plain-language filters, the assistant and writing help are offered, and
 * proposing changes is offered once its own switch is on; `excluded` names
 * the resources kept from the model (the demo's profiles opt out), and
 * `excludedFields` the fields kept back of the others, and `noWriting`
 * the text fields writing help is off for; the demo's unique strings,
 * products' SKU, tags' name and tickets' number. Computed per user, so it follows
 * the switch and the budget too.
 */
final class AiCapabilitiesConfiguredTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testTheShippedFeaturesAreOfferedAndWhatIsKeptBackIsExcluded(): void
    {
        self::assertSame([Feature::Filters, Feature::Assistant, Feature::AssistantWrites, Feature::Writing], AiShellContributor::shipped());

        $response = $this->client->get('/vector/schema', headers: $this->headers());
        $ai = $response->assertOk()->json()['capabilities']['ai'] ?? null;
        self::assertIsArray($ai);
        $fields = $ai['excludedFields'];
        $noWriting = $ai['noWriting'];
        unset($ai['excludedFields'], $ai['noWriting']);

        self::assertSame(['filters' => true, 'assistant' => true, 'assistantWrites' => false, 'writing' => true, 'excluded' => ['profiles' => true]], $ai);
        // per field, for the resources not kept back whole: users' email opts out, a password is never sent
        self::assertIsArray($fields);
        self::assertSame(['email', 'passwordHash'], $fields['users'] ?? null);
        self::assertSame(['imageKey'], $fields['products'] ?? null, 'an image field is never sent');
        self::assertArrayNotHasKey('profiles', $fields, 'a resource kept back whole is under excluded');
        self::assertArrayNotHasKey('orders', $fields, 'a resource with nothing kept back is absent');

        // writing help: off for a unique string, an identifier rather than prose; on for the rest of the text
        self::assertIsArray($noWriting);
        self::assertSame(['sku'], $noWriting['products'] ?? null);
        self::assertSame(['name'], $noWriting['tags'] ?? null);
        self::assertSame(['number'], $noWriting['tickets'] ?? null);
        self::assertCount(3, $noWriting, 'no other text field is a unique string');

        // proposing changes is off until its own switch is on
        $this->client->request('PUT', '/vector/settings/ai', body: ['assistantWrites' => true], headers: $this->adminHeaders())->assertOk();
        self::assertTrue($this->client->get('/vector/schema', headers: $this->headers())->assertOk()->json()['capabilities']['ai']['assistantWrites'] ?? null);
    }

    public function testTheAssistantFollowsItsOwnSwitch(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['assistantEnabled' => false], headers: $this->adminHeaders())->assertOk();
        $ai = $this->client->get('/vector/schema', headers: $this->headers())->assertOk()->json()['capabilities']['ai'] ?? null;

        self::assertIsArray($ai);
        self::assertTrue($ai['filters']);
        self::assertFalse($ai['assistant']);
    }

    public function testTheSwitchAndTheBudgetTurnFiltersOff(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersEnabled' => false, 'assistantEnabled' => false, 'writingEnabled' => false], headers: $this->adminHeaders())->assertOk();
        $off = $this->client->get('/vector/schema', headers: $this->headers())->assertOk();
        self::assertFalse($off->json()['capabilities']['ai']['filters'] ?? null);
        self::assertStringContainsString('"excluded":{}', $off->body(), 'nothing is excluded while no feature sends to a model');
        self::assertStringContainsString('"excludedFields":{}', $off->body());
        self::assertStringContainsString('"noWriting":{}', $off->body());

        $this->client->request('PUT', '/vector/settings/ai', body: ['filtersEnabled' => true, 'assistantEnabled' => true, 'dailyTokenBudgetPerUser' => 100], headers: $this->adminHeaders())->assertOk();
        $this->vectorQuery()->table('vector_ai_usage')->insert([
            'actor_id' => self::ACTOR_PREFIX . '01', 'impersonator_id' => null, 'feature' => 'filters', 'provider' => 'test-fake', 'model' => 'fake',
            'input_tokens' => 60, 'output_tokens' => 40, 'duration_ms' => 1, 'tool_calls' => 0, 'outcome' => 'answered',
            'created_at' => gmdate('Y-m-d H:i:s.000000'),
        ]);

        $spent = $this->client->get('/vector/schema', headers: $this->headers())->assertOk()->json()['capabilities']['ai'] ?? null;
        self::assertIsArray($spent);
        self::assertFalse($spent['filters']);
        self::assertFalse($spent['assistant'], 'the budget is the person\'s, for every feature');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return $this->aiHeaders(1);
    }
}
