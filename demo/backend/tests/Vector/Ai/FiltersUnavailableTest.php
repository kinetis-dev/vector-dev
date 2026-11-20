<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/** Without a provider — the demo as it ships — POST /vector/ai/filters is 403. */
final class FiltersUnavailableTest extends AiTestCase
{
    public function testWithoutAProviderItIsForbidden(): void
    {
        $this->client->post('/vector/ai/filters/orders', ['text' => 'paid orders'], $this->aiHeaders())
            ->assertStatus(403)
            ->assertJsonPath('error', 'Plain-language filters are not available to this account.');
    }
}
