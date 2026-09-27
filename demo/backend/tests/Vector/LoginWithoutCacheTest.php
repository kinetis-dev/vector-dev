<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * Without an application cache the demo still boots and serves the
 * admin to existing tokens; signing in answers a clear 503 instead of
 * running unthrottled.
 */
final class LoginWithoutCacheTest extends VectorTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'REDIS_HOST' => '', 'REDIS_URL' => '', 'REDIS_CLUSTER' => ''];
    }

    public function testSigningInAnswers503AndTheAdminStillWorks(): void
    {
        $refused = $this->client->post('/auth/login', body: ['email' => 'admin@vector.local', 'password' => 'password'])->assertStatus(503);
        self::assertStringContainsString('REDIS_HOST', $refused->body());
        $this->client->post('/auth/refresh', body: ['refreshToken' => str_repeat('0', 64)])->assertStatus(503);

        $this->client->get('/vector/me', headers: $this->adminHeaders())->assertOk();
    }
}
