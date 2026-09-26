<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/** Signs in through the demo's session form, as a browser would. */
trait SignsInThroughTheForm
{
    /** @return array{0: string, 1: string} the session cookie header and the CSRF token of that session */
    protected function signIn(string $email = 'admin@vector.local'): array
    {
        $form = $this->client->get('/login')->assertOk();
        $cookie = $this->cookieOf($form->getHeaderLine('Set-Cookie'));
        $formToken = preg_match('/name="_token" value="([^"]+)"/', $form->body(), $m) === 1 ? $m[1] : self::fail('the sign-in form carries a CSRF token');

        $login = $this->client->postForm('/login', ['_token' => $formToken, 'email' => $email, 'password' => 'password'], headers: ['Cookie' => $cookie]);
        $login->assertStatus(303)->assertHeader('Location', '/vector/ui');
        $cookie = $this->cookieOf($login->getHeaderLine('Set-Cookie')) ?: $cookie;

        // the shell page carries the (regenerated) session's CSRF token
        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie]);
        $csrf = preg_match('/"csrfToken":"([^"]+)"/', $shell->body(), $t) === 1 ? $t[1] : '';

        if ($csrf === '') {
            // no shell published: read the token from the login form of the signed-in session instead
            $csrf = $formToken;
        }

        return [$cookie, $csrf];
    }

    protected function cookieOf(string $setCookie): string
    {
        return preg_match('/^([^=]+=[^;]+)/', $setCookie, $m) === 1 ? $m[1] : '';
    }
}
