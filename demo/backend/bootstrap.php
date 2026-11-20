<?php

declare(strict_types=1);

use App\Auth\DemoActorResolver;
use App\Auth\DemoPasswordHasher;
use App\Auth\DemoQueueDashboardPolicy;
use App\Auth\DemoVectorAccess;
use App\Http\Diagnostics\RuntimeProbe;
use App\Vector\Ai\DemoAiReplies;
use Kinetis\AuthJwt\JwtAuthenticator;
use Kinetis\AuthJwt\JwtIssuer;
use Kinetis\AuthJwt\JwtSigningKey;
use Kinetis\AuthJwt\JwtVerificationKeys;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\Middleware\CorsMiddleware;
use Kinetis\Vector\Auth\ActorResolverInterface;
use Kinetis\Vector\Auth\PasswordHasherInterface;
use Kinetis\Vector\Auth\VectorAccessInterface;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\VectorAi\Testing\ScriptedReplies;

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

    // Vector's queue dashboard is view-only by default: here an admin may also retry, forget, prune and clear.
    $app->instance(QueueDashboardPolicyInterface::class, new DemoQueueDashboardPolicy());

    // The users resource lets an admin set a password: hashed here, the way the login verifies it.
    $app->instance(PasswordHasherInterface::class, new DemoPasswordHasher());

    // Background jobs run as the admin who started them: this rebuilds that identity in the worker.
    $app->bind(ActorResolverInterface::class, DemoActorResolver::class);

    // What a `fake` AI provider answers (VECTOR_AI_<NAME>_TYPE=fake): plain-language filters without a model.
    $app->instance(ScriptedReplies::class, DemoAiReplies::replies());

    // One per boot, for the development-only runtime diagnostic (App\Http\Diagnostics).
    $app->bind(RuntimeProbe::class, shared: true);

    // Standalone mode: the admin UI is served from its own origin.
    $origin = $config->required('VECTOR_UI_ORIGIN');

    $app->bind(CorsMiddleware::class, static fn (): CorsMiddleware => new CorsMiddleware(
        allowedOrigins: [$origin],
        allowedMethods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        allowedHeaders: ['Content-Type', 'Authorization', 'X-CSRF-Token', 'If-Match'],
        // Retry-After: the AI endpoints' spent budget says when it renews
        exposedHeaders: ['Content-Disposition', 'ETag', 'Retry-After'],
    ));
    $app->middleware(CorsMiddleware::class);
};
