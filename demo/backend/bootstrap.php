<?php

declare(strict_types=1);

use App\Auth\DemoActorResolver;
use App\Auth\DemoVectorAccess;
use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Middleware\CorsMiddleware;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;

return static function (AppScope $app, Config $config): void {
    $secret = $config->required('JWT_SECRET');

    $app->instance(JwtAuthenticator::class, new JwtAuthenticator(
        JwtVerificationKeys::hmacSecret($secret),
        expectedIssuer: 'vector-demo',
        acceptedAudiences: ['vector-demo'],
    ));

    $app->instance(JwtIssuer::class, new JwtIssuer(
        JwtSigningKey::hmacSecret($secret),
        issuer: 'vector-demo',
        audience: 'vector-demo',
    ));

    $app->instance(VectorAccessInterface::class, new DemoVectorAccess());

    // Background jobs run as the admin who started them: this rebuilds that identity in the worker.
    $app->bind(ActorResolverInterface::class, DemoActorResolver::class);

    // Standalone mode: the admin UI is served from its own origin.
    $origin = $config->required('VECTOR_UI_ORIGIN');

    $app->bind(CorsMiddleware::class, static fn (): CorsMiddleware => new CorsMiddleware(
        allowedOrigins: [$origin],
        allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        allowedHeaders: ['Content-Type', 'Authorization', 'X-CSRF-Token', 'If-Match'],
        exposedHeaders: ['Content-Disposition', 'ETag'],
    ));
    $app->middleware(CorsMiddleware::class);
};
