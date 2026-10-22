<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * Refresh-token reuse detection: a sign-in starts a token family, and a
 * redeemed refresh token presented again revokes that whole family —
 * whoever renewed first, owner or thief — answering exactly what an
 * unknown token does. Logout revokes the family too; another sign-in's
 * family is untouched.
 */
final class RefreshTokenReuseTest extends VectorTestCase
{
    public function testAReplayAfterRotationRevokesTheWholeFamily(): void
    {
        $r1 = $this->signIn();
        $r2 = $this->renew($r1);

        $this->refresh($r1)->assertStatus(401);
        $this->refresh($r2)->assertStatus(401);
    }

    public function testTheAttackerRenewingFirstEndsTheSameWay(): void
    {
        $stolen = $this->signIn();

        // the attacker redeems the stolen token first, then the owner presents it
        $attackers = $this->renew($stolen);
        $this->refresh($stolen)->assertStatus(401);

        $this->refresh($attackers)->assertStatus(401);
    }

    public function testLogoutRevokesThePresentedTokenAndItsSiblings(): void
    {
        $r1 = $this->signIn();
        $r2 = $this->renew($r1);

        $this->client->post('/auth/logout', body: ['refreshToken' => $r2])->assertStatus(204);

        $this->refresh($r2)->assertStatus(401);
        $this->refresh($r1)->assertStatus(401);
    }

    public function testLogoutWithATokenTheStoreStillHoldsRevokesTheTokensRotatedFromItsFamily(): void
    {
        // two tabs of one sign-in: one renewed, the other signs out with the token it still holds
        $r1 = $this->signIn();
        $r2 = $this->renew($r1);
        $r3 = $this->renew($r2);

        $this->client->post('/auth/logout', body: ['refreshToken' => $r2])->assertStatus(204);

        $this->refresh($r3)->assertStatus(401);
    }

    public function testAnUnbrokenChainKeepsRenewing(): void
    {
        $r1 = $this->signIn();
        $r2 = $this->renew($r1);
        $r3 = $this->renew($r2);

        $this->renew($r3);
    }

    public function testARevokedFamilyLeavesAnotherSignInAlone(): void
    {
        $mine = $this->signIn();
        $other = $this->signIn();

        $this->renew($mine);
        $this->refresh($mine)->assertStatus(401);

        $this->renew($other);
    }

    public function testAReplayIsRefusedExactlyAsAnUnknownTokenIs(): void
    {
        $r1 = $this->signIn();
        $r2 = $this->renew($r1);

        $replay = $this->refresh($r1)->assertStatus(401);
        $revoked = $this->refresh($r2)->assertStatus(401);
        $unknown = $this->refresh(str_repeat('0', 64))->assertStatus(401);

        self::assertSame($unknown->body(), $replay->body());
        self::assertSame($unknown->body(), $revoked->body());
    }

    private function signIn(): string
    {
        $login = $this->client->post('/auth/login', body: ['email' => 'admin@vector.local', 'password' => 'password'])->assertOk()->json();
        self::assertIsArray($login);
        self::assertIsString($login['refreshToken']);

        return $login['refreshToken'];
    }

    private function renew(string $refreshToken): string
    {
        $renewed = $this->refresh($refreshToken)->assertOk()->json();
        self::assertIsArray($renewed);
        self::assertIsString($renewed['refreshToken']);

        return $renewed['refreshToken'];
    }

    private function refresh(string $refreshToken): \Kinetis\Testing\TestResponse
    {
        return $this->client->post('/auth/refresh', body: ['refreshToken' => $refreshToken]);
    }
}
