<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\LoginAttempts;
use Nyholm\Psr7\ServerRequest;

/**
 * The demo's sign-in hardening, on the application cache (Redis): five
 * failed attempts lock one e-mail from one client address for fifteen
 * minutes, on the API and on the HTML form alike — never the account
 * itself, which its owner can still reach from their own address — and
 * twenty lock the address; a success clears the count. The API's access
 * token lasts fifteen minutes and a single-use refresh token renews it.
 */
final class LoginSecurityTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    private const string ATTACKER = '203.0.113.9';
    private const string OWNER = '198.51.100.7';

    /** @var list<string> e-mails whose failures a test recorded */
    private array $throttled = [];

    protected function tearDown(): void
    {
        $attempts = $this->app->get(LoginAttempts::class);
        self::assertInstanceOf(LoginAttempts::class, $attempts);

        foreach ($this->throttled as $email) {
            // the address counters too: 'unknown' is the test client's own
            foreach (['unknown', self::ATTACKER, self::OWNER] as $address) {
                $attempts->succeeded($email, $address);
            }
        }

        parent::tearDown();
    }

    public function testSixWrongPasswordsLockThatEmailFromThatAddressWithA429(): void
    {
        $email = $this->throttled[] = 'nobody-' . bin2hex(random_bytes(3)) . '@example.test';

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->login($email, 'wrong')->assertStatus(401);
        }

        $locked = $this->login($email, 'wrong')->assertStatus(429);
        self::assertStringContainsString('Too many failed sign-ins', $locked->body());
        self::assertGreaterThan(850, (int) $locked->getHeaderLine('Retry-After'));
        self::assertLessThanOrEqual(900, (int) $locked->getHeaderLine('Retry-After'));

        // locked even with the right password: the lock is checked before the password
        $this->login(strtoupper($email), 'password')->assertStatus(429);
    }

    public function testALockedEmailStillSignsInFromItsOwnersAddress(): void
    {
        $this->throttled[] = 'admin@vector.local';

        // someone else types the admin's e-mail with wrong passwords
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->loginFrom(self::ATTACKER, 'admin@vector.local', 'wrong')->assertStatus(401);
        }

        $this->loginFrom(self::ATTACKER, 'admin@vector.local', 'password')->assertStatus(429);
        $this->loginFrom(self::OWNER, 'admin@vector.local', 'password')->assertOk();
    }

    public function testOneAddressCannotWorkThroughManyAccounts(): void
    {
        for ($account = 1; $account <= 20; $account++) {
            $email = $this->throttled[] = "spray-{$account}-" . bin2hex(random_bytes(2)) . '@example.test';
            $this->loginFrom(self::ATTACKER, $email, 'wrong')->assertStatus(401);
        }

        $this->throttled[] = 'admin@vector.local';
        $this->loginFrom(self::ATTACKER, 'admin@vector.local', 'wrong')->assertStatus(429);
        $this->loginFrom(self::OWNER, 'admin@vector.local', 'password')->assertOk();
    }

    public function testASuccessClearsTheFailures(): void
    {
        $this->throttled[] = 'admin@vector.local';

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->login('admin@vector.local', 'wrong')->assertStatus(401);
        }

        $this->login('admin@vector.local', 'password')->assertOk();

        for ($attempt = 1; $attempt <= 4; $attempt++) {
            $this->login('admin@vector.local', 'wrong')->assertStatus(401);
        }

        $this->login('admin@vector.local', 'password')->assertOk();
    }

    public function testTheSignInFormIsThrottledToo(): void
    {
        $email = $this->throttled[] = 'form-' . bin2hex(random_bytes(3)) . '@example.test';
        $form = $this->client->get('/login')->assertOk();
        $cookie = $this->cookieOf($form->getHeaderLine('Set-Cookie'));
        $token = preg_match('/name="_token" value="([^"]+)"/', $form->body(), $m) === 1 ? $m[1] : self::fail('the form carries a CSRF token');

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->client->postForm('/login', ['_token' => $token, 'email' => $email, 'password' => 'wrong'], headers: ['Cookie' => $cookie])->assertStatus(401);
        }

        $locked = $this->client->postForm('/login', ['_token' => $token, 'email' => $email, 'password' => 'wrong'], headers: ['Cookie' => $cookie])->assertStatus(429);
        self::assertStringContainsString('Too many failed sign-ins. Try again in 15 minutes.', $locked->body());
    }

    public function testTheAccessTokenLastsFifteenMinutesAndTheRefreshTokenRotates(): void
    {
        $login = $this->login('admin@vector.local', 'password')->assertOk()->json();
        self::assertIsArray($login);
        self::assertSame(900, $login['expiresIn']);
        self::assertIsString($login['refreshToken']);
        self::assertGreaterThan(900, $login['refreshExpiresIn']);

        $renewed = $this->client->post('/auth/refresh', body: ['refreshToken' => $login['refreshToken']])->assertOk()->json();
        self::assertIsArray($renewed);
        self::assertNotSame($login['refreshToken'], $renewed['refreshToken']);
        self::assertNotSame($login['accessToken'], $renewed['accessToken']);
        self::assertSame($login['user'], $renewed['user']);
        $this->client->get('/vector/me', headers: ['Authorization' => 'Bearer ' . $renewed['accessToken']])->assertOk();

        // single use: the redeemed token is refused, the new one still works once
        $this->client->post('/auth/refresh', body: ['refreshToken' => $login['refreshToken']])->assertStatus(401);
        $this->client->post('/auth/refresh', body: ['refreshToken' => $renewed['refreshToken']])->assertOk();
    }

    public function testLogoutRevokesTheRefreshToken(): void
    {
        $login = $this->login('admin@vector.local', 'password')->assertOk()->json();
        self::assertIsArray($login);

        $this->client->post('/auth/logout', body: ['refreshToken' => $login['refreshToken']])->assertStatus(204);

        $this->client->post('/auth/refresh', body: ['refreshToken' => $login['refreshToken']])->assertStatus(401);
        $this->client->post('/auth/refresh', body: ['refreshToken' => str_repeat('0', 64)])->assertStatus(401);
    }

    private function login(string $email, string $password): \Kinetis\Testing\TestResponse
    {
        return $this->client->post('/auth/login', body: ['email' => $email, 'password' => $password]);
    }

    /** A sign-in from a given client address, as the worker sees it behind nginx. */
    private function loginFrom(string $address, string $email, string $password): \Kinetis\Testing\TestResponse
    {
        return $this->client->send(new ServerRequest(
            'POST',
            '/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
            '1.1',
            ['REMOTE_ADDR' => $address],
        ));
    }
}
