<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * VECTOR_SHELL_CSP gives the hosted shell a policy of its own: the shell
 * sends it, with the request's nonce, and the framework leaves it as it
 * is. Every other page keeps the application's SECURITY_CSP.
 */
final class HostedShellPolicyTest extends VectorTestCase
{
    use SignsInThroughTheForm;

    private const string POLICY = "default-src 'self'; script-src 'nonce-{nonce}'; style-src 'self' 'nonce-{nonce}'";

    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'VECTOR_SHELL_CSP' => self::POLICY];
    }

    public function testTheShellAnswersWithItsOwnPolicyAndTheRequestsNonce(): void
    {
        [$cookie] = $this->signIn();
        $shell = $this->client->get('/vector/ui', headers: ['Cookie' => $cookie]);

        if ($shell->getStatusCode() === 503) {
            self::markTestSkipped('The hosted UI is not published in this checkout.');
        }

        $shell->assertOk();
        $nonce = preg_match('/"cspNonce":"([^"]+)"/', $shell->body(), $n) === 1 ? $n[1] : self::fail('the shell publishes its nonce');
        self::assertSame(str_replace('{nonce}', $nonce, self::POLICY), $shell->getHeaderLine('Content-Security-Policy'));


        $login = $this->client->get('/login')->getHeaderLine('Content-Security-Policy');
        self::assertStringContainsString("frame-ancestors 'none'", $login, 'the sign-in page keeps the application policy');
    }
}
