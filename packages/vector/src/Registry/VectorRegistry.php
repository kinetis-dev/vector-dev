<?php

declare(strict_types=1);

namespace Kinetis\Vector\Registry;

use Kinetis\Cache\CacheableDiscoveryInterface;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Attributes\Dashboard;
use Kinetis\Vector\Attributes\Settings;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\Vector\Ui\PackageExtensions;
use Kinetis\Vector\Widgets\VectorDashboard;
use Kinetis\Vector\Exception\ResourceNotFoundException;
use ReflectionClass;

/**
 * The descriptor as a section of the AOT cache: compiled by `kinetis
 * build` from every #[Resource] class and the ORM's own metadata, and
 * bound on AppScope before any package bootstrap runs. Immutable and
 * request-neutral; the per-user permission overlay is computed elsewhere.
 *
 * It also carries the runtime UI extensions installed packages declare
 * in `extra.kinetis.vector.extensions` ({@see PackageExtensions}): read
 * from Composer's record at compile time, so which package boots first
 * makes no difference. They are not part of the descriptor the UI
 * reads, so {@see VERSION} does not change with them.
 */
final readonly class VectorRegistry implements CacheableDiscoveryInterface
{
    public const int VERSION = 4;

    /** @var array<string, ResourceDescriptor> */
    private array $descriptors;

    /** @var array<string, SettingsDescriptor> */
    private array $settings;

    /**
     * @param array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, uiExtensions?: list<array{package: string, root: string, entry: string}>} $data
     */
    private function __construct(private array $data)
    {
        $descriptors = [];

        foreach ($data['resources'] as $slug => $resource) {
            $descriptors[$slug] = new ResourceDescriptor($resource);
        }

        $this->descriptors = $descriptors;
        $settings = [];

        foreach ($data['settings'] as $name => $group) {
            $settings[$name] = new SettingsDescriptor($group);
        }

        $this->settings = $settings;
    }

    /**
     * Resource, settings and dashboard classes come from the operation's
     * shared class scan, entity metadata from the ORM's own discovery
     * section, which the context compiles once for every consumer, and
     * the packages' UI extensions from Composer's installed.json.
     */
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        $classes = [];
        $groups = [];
        $dashboards = [];

        foreach ([
            ...$context->projectClasses(),
            ...$context->packageClasses(),
        ] as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->getAttributes(Resource::class) !== []) {
                $classes[$class] = true;
            } elseif ($reflection->getAttributes(Settings::class) !== []) {
                if (!$reflection->isSubclassOf(SettingsGroup::class) || !$reflection->isInstantiable()) {
                    throw ResourceCompileException::resource($class, 'carries #[Settings] but is not a concrete SettingsGroup');
                }

                $groups[$class] = true;
            } elseif ($reflection->getAttributes(Dashboard::class) !== []) {
                if (!$reflection->isSubclassOf(VectorDashboard::class) || !$reflection->isInstantiable()) {
                    throw ResourceCompileException::resource($class, 'carries #[Dashboard] but is not a concrete VectorDashboard');
                }

                $dashboards[$class] = true;
            }
        }

        $metadata = MetadataRegistry::fromArray($context->compiled(OrmMetadata::class));
        $compiler = new ResourceCompiler($metadata, array_keys($classes));
        $resources = $compiler->compile();

        /** @var list<class-string<SettingsGroup>> $groupClasses */
        $groupClasses = array_keys($groups);
        /** @var list<class-string<VectorDashboard>> $dashboardClasses */
        $dashboardClasses = array_keys($dashboards);

        return [
            'version' => self::VERSION,
            'resources' => $resources,
            'settings' => SettingsCompiler::compile($groupClasses, $resources),
            'dashboards' => WidgetCompiler::dashboards($dashboardClasses),
            'uiExtensions' => PackageExtensions::discover($context->projectRoot),
        ];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        if (($data['version'] ?? null) !== self::VERSION) {
            throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a descriptor of another version');
        }

        if (!is_array($data['resources'] ?? null) || !is_array($data['settings'] ?? null) || !is_array($data['dashboards'] ?? null)) {
            throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'no resources, settings or dashboards map');
        }

        foreach ($data['resources'] as $slug => $resource) {
            if (!is_string($slug) || !is_array($resource) || ($resource['slug'] ?? null) !== $slug || !is_array($resource['fields'] ?? null)) {
                throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a malformed resource entry');
            }
        }

        $extensions = $data['uiExtensions'] ?? [];

        if (!is_array($extensions) || !array_is_list($extensions)) {
            throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a UI extensions entry that is not a list');
        }

        foreach ($extensions as $extension) {
            if (!is_array($extension) || !is_string($extension['package'] ?? null) || !is_string($extension['root'] ?? null) || !is_string($extension['entry'] ?? null)) {
                throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a malformed UI extension entry');
            }
        }

        /** @var array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, uiExtensions?: list<array{package: string, root: string, entry: string}>} $data */
        return new self($data);
    }

    /** @return array<string, array<string, mixed>> name => compiled dashboard, `main` first */
    public function dashboards(): array
    {
        return $this->data['dashboards'];
    }

    /**
     * @return array<string, mixed>
     * @throws ResourceNotFoundException
     */
    public function dashboard(string $name): array
    {
        return $this->data['dashboards'][$name] ?? throw ResourceNotFoundException::slug("dashboards/{$name}");
    }

    /** @return array<string, SettingsDescriptor> name => group, ordered by name */
    public function settings(): array
    {
        return $this->settings;
    }

    /** @throws ResourceNotFoundException */
    public function settingsGroup(string $name): SettingsDescriptor
    {
        return $this->settings[$name] ?? throw ResourceNotFoundException::slug("settings/{$name}");
    }

    /** @return array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, uiExtensions?: list<array{package: string, root: string, entry: string}>} */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * The UI extensions installed packages declare, by package name, then
     * in declared order; empty for a registry compiled without them.
     *
     * @return list<array{package: string, root: string, entry: string}>
     */
    public function uiExtensions(): array
    {
        return $this->data['uiExtensions'] ?? [];
    }

    /** @return array<string, ResourceDescriptor> slug => descriptor, ordered by slug */
    public function resources(): array
    {
        return $this->descriptors;
    }

    public function has(string $slug): bool
    {
        return isset($this->descriptors[$slug]);
    }

    /** @throws ResourceNotFoundException */
    public function resource(string $slug): ResourceDescriptor
    {
        return $this->descriptors[$slug] ?? throw ResourceNotFoundException::slug($slug);
    }
}
