<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

/**
 * GET, PUT and DELETE /vector/ai/credentials with VECTOR_AI_KEY set: a
 * key goes in, is stored sealed, and never comes back out.
 */
final class CredentialsEndpointTest extends AiTestCase
{
    private const string SECRET = 'sk-test-0123456789-SECRET';

    #[\Override]
    protected function aiConfig(): array
    {
        return [
            'VECTOR_AI_KEY' => self::KEY,
            'VECTOR_AI_PROVIDERS' => 'test-openai,test-env,test-aws,test-fake,test-other',
            'VECTOR_AI_TEST_OPENAI_TYPE' => 'openai',
            'VECTOR_AI_TEST_OTHER_TYPE' => 'openai',
            'VECTOR_AI_TEST_ENV_TYPE' => 'anthropic',
            'VECTOR_AI_TEST_ENV_KEY' => 'sk-ant-FROM-THE-ENVIRONMENT',
            'VECTOR_AI_TEST_AWS_TYPE' => 'bedrock',
            'VECTOR_AI_TEST_AWS_REGION' => 'eu-central-1',
            'VECTOR_AI_TEST_FAKE_TYPE' => 'fake',
        ];
    }

    public function testTheStatusNamesEveryProviderAndNeverAKey(): void
    {
        $response = $this->client->get('/vector/ai/credentials', headers: $this->adminHeaders())->assertOk();
        $status = $response->json();
        self::assertIsArray($status);

        self::assertSame(['test-openai', 'test-env', 'test-aws', 'test-fake', 'test-other'], array_keys($status));
        self::assertSame(['set' => false, 'managedBy' => 'admin', 'storable' => true], $status['test-openai']);
        self::assertSame(['set' => true, 'managedBy' => 'environment', 'storable' => false], $status['test-env']);
        self::assertSame(['set' => false, 'managedBy' => 'environment', 'storable' => false], $status['test-aws']);
        self::assertFalse($status['test-fake']['set']);
        self::assertStringNotContainsString('FROM-THE-ENVIRONMENT', $response->body());
    }

    public function testAStoredKeyIsSealedAndNeverReturned(): void
    {
        $stored = $this->client->request('PUT', '/vector/ai/credentials/test-openai', body: ['key' => self::SECRET], headers: $this->adminHeaders())->assertOk();
        self::assertStringNotContainsString(self::SECRET, $stored->body());
        self::assertTrue($stored->json()['set'] ?? null);
        self::assertSame('1', $stored->json()['updatedBy'] ?? null);

        $listed = $this->client->get('/vector/ai/credentials', headers: $this->adminHeaders())->assertOk();
        self::assertStringNotContainsString(self::SECRET, $listed->body());
        self::assertStringNotContainsString(base64_encode(self::SECRET), $listed->body());
        $status = $listed->json()['test-openai'] ?? null;
        self::assertIsArray($status);
        self::assertTrue($status['set']);
        self::assertSame('admin', $status['managedBy']);
        self::assertSame('1', $status['updatedBy']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $status['updatedAt']);
        self::assertArrayNotHasKey('sealedWithAnotherKey', $status);

        $row = $this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->first();
        self::assertIsArray($row);
        self::assertStringNotContainsString(self::SECRET, (string) $row['ciphertext'], 'stored sealed, not in plain text');
        self::assertSame(24, strlen((string) base64_decode((string) $row['nonce'], true)));

        $this->client->delete('/vector/ai/credentials/test-openai', headers: $this->adminHeaders())->assertOk()->assertJsonPath('set', false);
        self::assertNull($this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->first());
    }

    public function testStoringAndRemovingAKeyIsAuditedWithoutTheKey(): void
    {
        $audit = fn (): array => $this->vectorQuery()->table('vector_audit')
            ->where('resource', '=', \Kinetis\VectorAi\Http\CredentialsController::AUDIT_RESOURCE)
            ->where('record_id', '=', 'test-openai')
            ->orderBy('id', 'ASC')
            ->get();

        $this->client->request('PUT', '/vector/ai/credentials/test-openai', body: ['key' => self::SECRET], headers: $this->adminHeaders())->assertOk();
        $this->client->delete('/vector/ai/credentials/test-openai', headers: $this->adminHeaders())->assertOk();
        // nothing stored any more: nothing removed, nothing recorded
        $this->client->delete('/vector/ai/credentials/test-openai', headers: $this->adminHeaders())->assertOk();

        $rows = $audit();
        self::assertSame(['set', 'delete'], array_column($rows, 'event'));

        foreach ($rows as $row) {
            self::assertSame('1', (string) $row['actor_id']);
            self::assertNull($row['impersonator_id']);
            self::assertSame('[]', (string) $row['changes']);
            $encoded = json_encode($row, JSON_THROW_ON_ERROR);

            foreach ([self::SECRET, base64_encode(self::SECRET), substr(self::SECRET, 0, 8), substr(self::SECRET, -6)] as $part) {
                self::assertStringNotContainsString($part, $encoded, 'no part of the key is in the audit trail');
            }
        }
    }

    public function testAKeyCopiedIntoAnotherProvidersRowDoesNotOpen(): void
    {
        $config = $this->app->get(\Kinetis\VectorAi\Config\AiConfig::class);
        $store = $this->app->get(\Kinetis\VectorAi\Secrets\CredentialStore::class);
        self::assertInstanceOf(\Kinetis\VectorAi\Config\AiConfig::class, $config);
        self::assertInstanceOf(\Kinetis\VectorAi\Secrets\CredentialStore::class, $store);
        $openai = $config->providers['test-openai'];
        $other = $config->providers['test-other'];
        $store->put($openai, self::SECRET, '1');
        $store->put($other, 'sk-other-key', '1');
        self::assertSame(self::SECRET, $store->key($openai));
        self::assertSame('sk-other-key', $store->key($other));

        // the two rows' sealed values swapped, each under the same key: neither opens for the provider it now sits under
        $rows = [];

        foreach (['test-openai', 'test-other'] as $provider) {
            $row = $this->vectorQuery()->table('vector_ai_credentials')->select('ciphertext', 'nonce', 'key_id')->where('provider', '=', $provider)->first();
            self::assertIsArray($row);
            $rows[$provider] = $row;
        }

        self::assertSame($rows['test-openai']['key_id'], $rows['test-other']['key_id']);
        $this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->update(['ciphertext' => $rows['test-other']['ciphertext'], 'nonce' => $rows['test-other']['nonce']]);
        $this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-other')->update(['ciphertext' => $rows['test-openai']['ciphertext'], 'nonce' => $rows['test-openai']['nonce']]);

        self::assertNull($store->key($openai));
        self::assertNull($store->key($other));
    }

    public function testAKeySealedWithAnotherKeyIsReported(): void
    {
        $this->vectorQuery()->table('vector_ai_credentials')->insert([
            'provider' => 'test-openai',
            'ciphertext' => base64_encode(random_bytes(40)),
            'nonce' => base64_encode(random_bytes(24)),
            'key_id' => 'deadbeef',
            'updated_by' => '1',
            'updated_at' => '2026-11-01 10:00:00.000000',
        ]);

        $status = $this->client->get('/vector/ai/credentials', headers: $this->adminHeaders())->assertOk()->json()['test-openai'] ?? null;

        self::assertIsArray($status);
        self::assertTrue($status['set']);
        self::assertTrue($status['sealedWithAnotherKey']);
        self::assertSame('Sealed with another key; re-enter it.', $status['problem']);
    }

    public function testUnknownAndEnvironmentManagedProvidersAreRefused(): void
    {
        $this->client->request('PUT', '/vector/ai/credentials/nope', body: ['key' => self::SECRET], headers: $this->adminHeaders())->assertNotFound();
        $this->client->request('PUT', '/vector/ai/credentials/test-env', body: ['key' => self::SECRET], headers: $this->adminHeaders())->assertStatus(409);
        $this->client->request('PUT', '/vector/ai/credentials/test-aws', body: ['key' => self::SECRET], headers: $this->adminHeaders())->assertStatus(409);
        $this->client->request('PUT', '/vector/ai/credentials/test-openai', body: ['key' => ''], headers: $this->adminHeaders())->assertValidationError('key');
        $this->client->request('PUT', '/vector/ai/credentials/test-openai', body: ['key' => "two\nlines"], headers: $this->adminHeaders())->assertValidationError('key');
        self::assertNull($this->vectorQuery()->table('vector_ai_credentials')->where('provider', '=', 'test-openai')->first());
    }

    public function testOnlyTheAdminGroupReachesIt(): void
    {
        $this->client->get('/vector/ai/credentials')->assertStatus(401);
        $this->client->get('/vector/ai/credentials', headers: $this->editorHeaders())->assertStatus(403);
    }
}
