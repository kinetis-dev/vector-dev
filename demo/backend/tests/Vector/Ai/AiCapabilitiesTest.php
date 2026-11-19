<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/**
 * capabilities.ai in GET /vector/schema with kinetis/vector-ai installed
 * and, as the demo ships, no provider configured: present, and every
 * feature off.
 */
final class AiCapabilitiesTest extends AiTestCase
{
    public function testUnconfiguredEveryFeatureIsOff(): void
    {
        $response = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk();
        $ai = $response->json()['capabilities']['ai'] ?? null;

        self::assertSame(['filters' => false, 'assistant' => false, 'assistantWrites' => false, 'writing' => false, 'excluded' => []], $ai);
        self::assertStringContainsString('"excluded":{}', $response->body(), 'excluded is an object');
    }

    public function testTheAiSettingsGroupIsListedForWhoeverMayConfigureIt(): void
    {
        $this->client->get('/vector/schema', headers: $this->adminHeaders())
            ->assertOk()
            ->assertJsonPath('settings.0.name', 'ai')
            ->assertJsonPath('settings.0.label', 'AI assistant');
    }
}
