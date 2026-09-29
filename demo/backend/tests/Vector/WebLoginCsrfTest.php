<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * A sign-in page left open until its session expired posts a token the
 * new session never issued. The browser gets the form back with a notice
 * and its e-mail, not the CSRF check's JSON, and signs in from there.
 */
final class WebLoginCsrfTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    public function testAnExpiredSignInPageComesBackAsAFormWithANotice(): void
    {
        // the session behind the page is gone: the browser sends no live cookie, only the old page's token
        $refused = $this->client->postForm('/login', ['_token' => str_repeat('a', 64), 'email' => 'admin@vector.local', 'password' => 'password']);
        $refused->assertStatus(303)->assertHeader('Location', '/login');
        self::assertStringNotContainsString('CSRF token mismatch', $refused->body());
        $cookie = $this->cookieOf($refused->getHeaderLine('Set-Cookie'));
        self::assertNotSame('', $cookie, 'the notice travels in the new session');

        $form = $this->client->get('/login', headers: ['Cookie' => $cookie])->assertOk();
        self::assertStringStartsWith('text/html', $form->getHeaderLine('Content-Type'));
        self::assertStringContainsString('its session expired. Please sign in again.', $form->body());
        self::assertStringContainsString('value="admin@vector.local"', $form->body(), 'the e-mail is kept');
        self::assertStringNotContainsString('value="password"', $form->body(), 'the password never is');

        // the notice is shown once
        self::assertStringNotContainsString('session expired', $this->client->get('/login', headers: ['Cookie' => $cookie])->body());

        // and the fresh form signs in
        $token = preg_match('/name="_token" value="([^"]+)"/', $form->body(), $m) === 1 ? $m[1] : self::fail('the form carries a new token');
        $this->client->postForm('/login', ['_token' => $token, 'email' => 'admin@vector.local', 'password' => 'password'], headers: ['Cookie' => $cookie])
            ->assertStatus(303)
            ->assertHeader('Location', '/vector/ui');
    }

    public function testAStaleTokenOnALiveSessionDoesNothingElse(): void
    {
        [$cookie] = $this->signIn();

        // a sign-out with a token this session did not issue: refused, readable, and still signed in
        $this->client->postForm('/logout', ['_token' => str_repeat('b', 64)], headers: ['Cookie' => $cookie])
            ->assertStatus(303)
            ->assertHeader('Location', '/login');
        $this->client->get('/login', headers: ['Cookie' => $cookie])->assertStatus(303)->assertHeader('Location', '/vector/ui');

        // an address that is no e-mail is not carried back
        $refused = $this->client->postForm('/login', ['email' => str_repeat('x', 300), 'password' => 'p']);
        $form = $this->client->get('/login', headers: ['Cookie' => $this->cookieOf($refused->getHeaderLine('Set-Cookie'))])->assertOk();
        self::assertStringContainsString('value=""', $form->body());
    }
}
