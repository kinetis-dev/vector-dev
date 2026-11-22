<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/** Without a provider (the demo as it ships) POST /vector/ai/writing is 403, and the flag is off. */
final class WritingUnavailableTest extends AiTestCase
{
    public function testWithoutAProviderItIsForbidden(): void
    {
        $this->client->post('/vector/ai/writing/orders/notes', ['operation' => 'shorten', 'text' => 'Leave it at the door.'], $this->aiHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error', 'Writing help is not available to this account.');
        self::assertFalse($this->client->get('/vector/schema', headers: $this->aiHeaders())->assertOk()->json()['capabilities']['ai']['writing'] ?? null);
    }
}
