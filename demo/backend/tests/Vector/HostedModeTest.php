<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * Hosted mode: the application's session cookie authenticates the admin
 * API and the shell page. Runs against the UI bundle `vector:install`
 * published, so a checkout without it sees the 503 branch instead.
 */
final class HostedModeTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    public function testTheShellRedirectsAnAnonymousVisitorToTheApplicationLogin(): void
    {
        $this->client->get('/vector/ui')->assertStatus(302)->assertHeader('Location', '/login');
        $this->client->get('/vector/ui/r/products/5/edit')->assertStatus(302)->assertHeader('Location', '/login');
    }

    public function testASessionSignedInThroughTheFormReachesTheShellAndTheApi(): void
    {
        [$cookie, $csrf] = $this->signIn();

        // a session-authenticated write without the CSRF token is refused, bundle or no bundle
        $this->client->post('/vector/r/tags', body: ['name' => 'hosted-test', 'color' => '#123456'], headers: ['Cookie' => $cookie])
            ->assertStatus(403);

        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie]);

        if ($shell->getStatusCode() === 503) {
            self::assertStringContainsString('vector:install', $shell->body());
            self::markTestSkipped('The hosted UI is not published in this checkout.');
        }

        $shell->assertOk()->assertHeader('Cache-Control', 'no-store');
        $html = $shell->body();
        self::assertStringContainsString('window.__VECTOR__ = {', $html);
        self::assertStringContainsString('"authMode":"cookie"', $html);
        self::assertStringContainsString('"loginUrl":"/login"', $html);
        self::assertStringContainsString('"locale":null', $html, 'no VECTOR_UI_LOCALE: the bundle keeps its default');
        self::assertStringContainsString('"csrfToken":"', $html);
        self::assertStringContainsString('"name":"Vector Admin"', $html);
        self::assertStringContainsString('src="/vector/ui/_nuxt/', $html, 'the bundle is built for the hosted base URL');
        self::assertStringContainsString('"branding":{"title":"Vector"', $html, 'branding rides along for the first paint');

        // a strict CSP: the nonce is on every inline script, and on nothing else
        $nonce = preg_match('/"cspNonce":"([^"]+)"/', $html, $n) === 1 ? $n[1] : self::fail('the shell publishes its nonce');
        $csp = $shell->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self' 'nonce-{$nonce}'", $csp);
        self::assertStringNotContainsString('{nonce}', $csp);
        self::assertSame(0, preg_match('/<script(?![^>]*\bnonce=)(?![^>]*\bsrc=)[^>]*>/', $html), 'every inline script carries the nonce');
        self::assertSame(0, preg_match('/<script[^>]*\bsrc=[^>]*\bnonce=/', $html), 'an external script needs none');

        $this->client->get('/vector/ui/r/products/5/edit', headers: ['Cookie' => $cookie])->assertOk();
        $this->client->get('/vector/schema', headers: ['Cookie' => $cookie])->assertOk()->assertJsonPath('resources.products.slug', 'products');
        $adminId = $this->app->get(\Kinetis\Persistence\Contract\MysqlLink::class);
        self::assertInstanceOf(\Kinetis\Persistence\Contract\MysqlLink::class, $adminId);
        $admin = (new \Kinetis\QueryBuilder\Query($adminId))->table('users')->select('id')->where('email', '=', 'admin@vector.local')->first();
        self::assertIsArray($admin);
        $this->client->get('/vector/me', headers: ['Cookie' => $cookie])->assertOk()->assertJsonPath('id', (int) $admin['id']);

        // a write with the token the shell handed out goes through
        $created = $this->client->post('/vector/r/tags', body: ['name' => 'hosted-test', 'color' => '#123456'], headers: ['Cookie' => $cookie, 'X-CSRF-Token' => $csrf])
            ->assertCreated()
            ->json();
        self::assertIsArray($created);
        $this->client->delete('/vector/r/tags/' . $created['data']['id'], headers: ['Cookie' => $cookie, 'X-CSRF-Token' => $csrf])->assertStatus(204);

        $this->client->postForm('/logout', ['_token' => $csrf], headers: ['Cookie' => $cookie])->assertStatus(303)->assertHeader('Location', '/login');
    }

    public function testTheApplicationsPolicyCoversTheSignInPageWithTheRequestsNonce(): void
    {
        $form = $this->client->get('/login')->assertOk();
        $csp = $form->getHeaderLine('Content-Security-Policy');
        $nonce = preg_match("/style-src 'self' 'nonce-([^']+)'/", $csp, $n) === 1 ? $n[1] : self::fail('SECURITY_CSP, with its {nonce} replaced');
        self::assertStringContainsString('<style nonce="' . $nonce . '">', $form->body());

        $again = $this->client->get('/login')->assertOk()->getHeaderLine('Content-Security-Policy');
        self::assertStringNotContainsString($nonce, $again, 'a fresh nonce per request');
    }

    public function testAnEditorSessionIsSignedInButNotAdmitted(): void
    {
        [$cookie] = $this->signIn('editor@vector.local');

        $this->client->get('/vector/schema', headers: ['Cookie' => $cookie])->assertStatus(403);
    }
}
