<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/**
 * Without VECTOR_AI_KEY an administrator cannot store a provider key: 403,
 * and nothing is written. A key stored before is reported as present but
 * unusable until the key is set, not as sealed with another key.
 */
final class CredentialsWithoutKeyTest extends AiTestCase
{
    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_KEY' => '',
            'VECTOR_AI_PROVIDERS' => 'test-openai',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
        ];
    }

    public function testStoringIsForbiddenWithoutVectorAiKey(): void
    {
        $this->client->request('PUT', '/vector/ai/credentials/test-openai', body: ['key' => 'sk-test-secret'], headers: $this->adminHeaders())
            ->assertStatus(403)
            ->assertBodyContains('Set VECTOR_AI_KEY to store provider keys');

        self::assertNull($this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->first());
        $this->client->get('/vector/ai/credentials', headers: $this->adminHeaders())->assertOk()->assertJsonPath('test-openai.storable', false);
    }

    public function testAStoredKeyAsksForVectorAiKeyRatherThanReEntry(): void
    {
        $this->vectorQuery()->table('vector_ai_credentials')->insert([
            'provider' => 'test-openai',
            'ciphertext' => base64_encode('sealed-elsewhere'),
            'nonce' => base64_encode(str_repeat("\0", 24)),
            'key_id' => 'abcdef01',
            'updated_by' => 'test-admin',
            'updated_at' => '2026-11-01 10:00:00.000000',
        ]);

        $status = $this->client->get('/vector/ai/credentials', headers: $this->adminHeaders())->assertOk()->json()['test-openai'] ?? null;
        self::assertIsArray($status);

        self::assertTrue($status['set']);
        self::assertSame('admin', $status['managedBy']);
        self::assertFalse($status['storable']);
        self::assertArrayNotHasKey('sealedWithAnotherKey', $status);
        self::assertSame('Set VECTOR_AI_KEY to use the stored key.', $status['problem']);
        self::assertSame('test-admin', $status['updatedBy']);
    }
}
