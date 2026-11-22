<?php

declare(strict_types=1);

namespace Kinetis\VectorAi;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Auth\VectorAiAccess;
use Kinetis\VectorAi\Config\AiConfig;
use Kinetis\VectorAi\Secrets\SecretBox;

/**
 * Wiring only, mirroring Kinetis\Vector\PackageBootstrap: binds the
 * request-neutral, immutable objects every request shares (the parsed
 * configuration, the transports built over it and the box that seals
 * provider keys) and the default access policy, which an application
 * replaces by binding its own AiAccessInterface on AppScope. Everything
 * request-scoped (the resolver, the credential store, the ledger) is
 * built per request by the request container. An invalid VECTOR_AI_*
 * value stops the boot here, naming its key.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $ai = AiConfig::fromConfig($config);
        $app->instance(AiConfig::class, $ai);
        $app->instance(AiTransports::class, AiTransports::fromConfig($ai));
        $app->instance(SecretBox::class, SecretBox::fromConfig($config));

        if (!$app->has(AiAccessInterface::class)) {
            $app->bind(AiAccessInterface::class, VectorAiAccess::class);
        }
    }
}
