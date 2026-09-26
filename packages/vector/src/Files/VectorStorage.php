<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use League\Flysystem\FilesystemOperator;

/**
 * Where uploaded files for file and image fields live: the application's
 * own `FilesystemOperator` (kinetis/storage, bound when
 * `FILESYSTEM_DRIVER` is set), or the named storage connection
 * `VECTOR_STORAGE_CONNECTION` when set, built by kinetis/storage's own
 * factory from its `FILESYSTEM_<NAME>_*` keys. Built once per worker, on
 * first use. Availability reads configuration only.
 */
final class VectorStorage
{
    private const string FACTORY = 'Kinetis\Storage\FilesystemFactory';

    private ?FilesystemOperator $built = null;

    private function __construct(
        private readonly AppScope $app,
        private readonly Config $config,
        public readonly ?string $connection,
    ) {}

    public static function fromConfig(AppScope $app, Config $config): self
    {
        $connection = trim($config->string('VECTOR_STORAGE_CONNECTION', ''));

        return new self($app, $config, $connection === '' ? null : VectorDatabase::validated($connection, 'VECTOR_STORAGE_CONNECTION'));
    }

    /** Why uploads are unavailable, or null when they are available. */
    public function unavailableReason(): ?string
    {
        if (!interface_exists(FilesystemOperator::class)) {
            return 'Uploads need kinetis/storage.';
        }

        if ($this->connection === null) {
            return $this->app->has(FilesystemOperator::class) ? null : 'Uploads need a filesystem: set FILESYSTEM_DRIVER, or VECTOR_STORAGE_CONNECTION for storage of Vector\'s own.';
        }

        $key = Config::scopedKey('FILESYSTEM_ROOT', $this->connection);
        $driver = $this->config->string(Config::scopedKey('FILESYSTEM_DRIVER', $this->connection), 'local');

        return $driver === 'local' && $this->config->get($key) === null
            ? "VECTOR_STORAGE_CONNECTION names the storage connection \"{$this->connection}\", but {$key} is not set."
            : null;
    }

    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function filesystem(): FilesystemOperator
    {
        $reason = $this->unavailableReason();

        if ($reason !== null) {
            throw new ForbiddenOperationException($reason);
        }

        if ($this->connection === null) {
            $filesystem = $this->app->get(FilesystemOperator::class);

            return $filesystem instanceof FilesystemOperator ? $filesystem : throw new \LogicException('FilesystemOperator did not resolve to a filesystem.');
        }

        if ($this->built === null) {
            $factory = self::FACTORY;

            if (!class_exists($factory)) {
                throw new ForbiddenOperationException('Uploads need kinetis/storage.');
            }

            /** @var FilesystemOperator $filesystem */
            $filesystem = $factory::fromConfig($this->config, $this->connection);
            $this->built = $filesystem;
        }

        return $this->built;
    }
}
