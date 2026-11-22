<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Tests\Vector\VectorTestCase;

/**
 * The demo with kinetis/vector-ai configured as a test needs it: the
 * VECTOR_AI_* values aiConfig() gives on top of the demo's own, which
 * configure no provider. Rows a test leaves in vector_ai_credentials,
 * vector_ai_usage and the provider keys' audit trail for its own
 * providers and actors are removed after it. The demo's actors have
 * integer ids, so a test that calls an AI endpoint signs in as one of
 * its own (aiHeaders()), in a range no seeded user reaches.
 */
abstract class AiTestCase extends VectorTestCase
{
    /** A VECTOR_AI_KEY for the tests: 32 bytes, fixed so a sealed row opens on the next request. */
    protected const string KEY = 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=';

    /** The ids of the tests' own actors start with this; none is a seeded user. */
    protected const string ACTOR_PREFIX = '9902';

    /**
     * An admin of the tests' own: `9902<n>`.
     *
     * @return array<string, string>
     */
    protected function aiHeaders(int $n = 1): array
    {
        return ['Authorization' => 'Bearer ' . $this->issue(self::ACTOR_PREFIX . str_pad((string) $n, 2, '0', STR_PAD_LEFT), true)];
    }

    /** @return array<string, string> */
    protected function aiConfig(): array
    {
        return [];
    }

    /** @return array<string, string> */
    #[\Override]
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), ...$this->aiConfig()];
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_ai_credentials')->where('provider', 'LIKE', 'test-%')->delete();
        $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', 'LIKE', 'test-%')->delete();
        $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', 'LIKE', self::ACTOR_PREFIX . '%')->delete();
        $this->vectorQuery()->table('vector_audit')->where('resource', '=', \Kinetis\VectorAi\Http\CredentialsController::AUDIT_RESOURCE)->where('record_id', 'LIKE', 'test-%')->delete();
        parent::tearDown();
    }
}
