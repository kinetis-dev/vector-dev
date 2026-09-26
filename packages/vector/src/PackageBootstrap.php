<?php

declare(strict_types=1);

namespace Kinetis\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Files\VectorStorage;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\Ui\VectorUi;
use Kinetis\Vector\Widgets\WidgetCache;

/**
 * Wiring only: binds the hosted UI document, the application's login
 * and logout URLs and the deployment's shell additions once per
 * worker. Everything else Vector registers is discovered from
 * attributes.
 */
final readonly class PackageBootstrap implements PackageBootstrapInterface
{
    public function register(AppScope $app, Config $config): void
    {
        $root = $app->get(ProjectRoot::class);

        if (!$root instanceof ProjectRoot) {
            throw new \LogicException('The framework binds ProjectRoot before package bootstraps run.');
        }

        $app->instance(VectorUi::class, VectorUi::fromConfig($config, $root->path));
        $app->instance(VectorDefaults::class, VectorDefaults::fromConfig($config));
        $app->instance(WidgetCache::class, new WidgetCache());
        // Vector's own tables, on VECTOR_DB_CONNECTION or the default connection; the link is resolved on first use.
        $app->instance(VectorDatabase::class, VectorDatabase::fromConfig($app, $config));
        // Vector's background jobs: VECTOR_QUEUE on the application's queue, or on VECTOR_QUEUE_CONNECTION's backend.
        $app->instance(VectorQueue::class, VectorQueue::fromConfig($app, $config));
        // Uploaded files for file and image fields: the application's filesystem, or VECTOR_STORAGE_CONNECTION's.
        $app->instance(VectorStorage::class, VectorStorage::fromConfig($app, $config));
    }
}
