<?php

declare(strict_types=1);

namespace Kinetis\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\PackageBootstrapInterface;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Auth\PasswordHasherInterface;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Database\Connections;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Files\VectorStorage;
use Kinetis\Vector\Write\EntityWriter;
use Kinetis\Vector\Jobs\VectorQueue;
use Kinetis\Vector\OpenApi\OpenApiCache;
use Kinetis\Vector\Translation\MessagesTranslator;
use Kinetis\Vector\Translation\TranslatorInterface;
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
    #[\Override]
    public function register(AppScope $app, Config $config): void
    {
        $root = $app->get(ProjectRoot::class);

        if (!$root instanceof ProjectRoot) {
            throw new \LogicException('The framework binds ProjectRoot before package bootstraps run.');
        }

        $app->instance(VectorUi::class, VectorUi::fromConfig($config, $root->path));
        $app->instance(VectorDefaults::class, VectorDefaults::fromConfig($config));
        $app->instance(WidgetCache::class, new WidgetCache());
        // The schemas every GET /vector/openapi.json shares, whoever asks: a request-neutral memo.
        $app->instance(OpenApiCache::class, new OpenApiCache());
        // Vector's own tables, on VECTOR_DB_CONNECTION or the default connection; the link is resolved on first use.
        $app->instance(VectorDatabase::class, VectorDatabase::fromConfig($app, $config));
        // The dialect of each connection, and the reflection the default writer needs: request-neutral memos.
        $app->instance(Connections::class, new Connections($app, $config));
        $app->instance(EntityWriter::class, new EntityWriter());
        // Vector's background jobs: VECTOR_QUEUE on the application's queue, or on VECTOR_QUEUE_CONNECTION's backend.
        $app->instance(VectorQueue::class, VectorQueue::fromConfig($app, $config));
        // Uploaded files for file and image fields: the application's filesystem, or VECTOR_STORAGE_CONNECTION's.
        $app->instance(VectorStorage::class, VectorStorage::fromConfig($app, $config));
        // A hashed password field needs the application's hasher, which its bootstrap.php binds after this runs:
        // the first unit of work checks, so a missing binding fails closed with its reason rather than storing plaintext.
        $registry = $app->get(VectorRegistry::class);

        if ($registry instanceof VectorRegistry && ($hashed = self::fieldsHashedByTheApplication($registry)) !== []) {
            $app->onRequestScopeCreated(static function () use ($app, $hashed): void {
                if (!$app->has(PasswordHasherInterface::class)) {
                    throw new \LogicException(implode(', ', $hashed) . ' hashes passwords through ' . PasswordHasherInterface::class . ', but the application binds no implementation on AppScope; bind its hasher in bootstrap.php.');
                }
            });
        }

        // The labels' translations: read once, request-neutral.
        if (!$app->has(TranslatorInterface::class)) {
            $directory = trim($config->string('VECTOR_TRANSLATIONS_DIR', ''));
            $directories = [dirname(__DIR__) . '/translations'];

            if ($directory !== '') {
                $directories[] = str_starts_with($directory, '/') ? $directory : $root->path . '/' . $directory;
            }

            $app->instance(TranslatorInterface::class, MessagesTranslator::fromDirectories($directories));
        }
    }

    /**
     * The `resource.field` names of the password fields hashed through
     * the interface the application binds; one naming its own hasher class
     * is built by the request container and is not listed.
     *
     * @return list<string>
     */
    private static function fieldsHashedByTheApplication(VectorRegistry $registry): array
    {
        $names = [];

        foreach ($registry->resources() as $slug => $descriptor) {
            foreach ($descriptor->fields() as $name => $field) {
                if (($field['props']['hasher'] ?? null) === PasswordHasherInterface::class) {
                    $names[] = "{$slug}.{$name}";
                }
            }
        }

        return $names;
    }
}
