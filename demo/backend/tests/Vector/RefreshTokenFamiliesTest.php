<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\RefreshTokenFamilies;
use Kinetis\AuthJwt\Exception\RefreshTokenUnavailableException;
use Kinetis\SimpleCache\Exception\CacheException;
use Psr\SimpleCache\CacheInterface;

/** The family records keep no raw token, and a cache that fails refuses rather than lets a renewal through. */
final class RefreshTokenFamiliesTest extends VectorTestCase
{
    private function cache(): CacheInterface
    {
        $cache = $this->app->get(CacheInterface::class);
        self::assertInstanceOf(CacheInterface::class, $cache);

        return $cache;
    }

    public function testARedeemedTokenIsRememberedByItsHashOnly(): void
    {
        $cache = $this->cache();
        $families = new RefreshTokenFamilies($cache);
        $token = bin2hex(random_bytes(32));
        $family = $families->start();
        $endsAt = time() + 60;

        $families->redeemed($token, $family, $endsAt);

        self::assertSame(['family' => $family, 'sessionEndsAt' => $endsAt], $families->redeemedFamily($token));
        self::assertNull($families->redeemedFamily(bin2hex(random_bytes(32))));
        self::assertFalse($cache->has('jwt-refresh-used.' . $token));
        self::assertTrue($cache->has('jwt-refresh-used.' . hash('sha256', $token)));
    }

    public function testRevocationIsPerFamily(): void
    {
        $families = new RefreshTokenFamilies($this->cache());
        [$revoked, $intact] = [$families->start(), $families->start()];

        $families->revoke($revoked, time() + 60);

        self::assertTrue($families->isRevoked($revoked));
        self::assertFalse($families->isRevoked($intact));
    }

    public function testAnUnreadableCacheRefusesInsteadOfReportingTheFamilyIntact(): void
    {
        $families = new RefreshTokenFamilies($this->failingCache());

        $this->expectException(RefreshTokenUnavailableException::class);
        $families->isRevoked('family');
    }

    public function testAnUnwritableCacheRefusesInsteadOfForgettingTheRedeem(): void
    {
        $families = new RefreshTokenFamilies($this->failingCache());

        $this->expectException(RefreshTokenUnavailableException::class);
        $families->redeemed('token', 'family', time() + 60);
    }

    private function failingCache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(CacheException::forOperation('get', new \RuntimeException('down')));
        $cache->method('set')->willThrowException(CacheException::forOperation('set', new \RuntimeException('down')));

        return $cache;
    }
}
