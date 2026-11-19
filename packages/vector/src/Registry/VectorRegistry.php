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
use Kinetis\Vector\Attributes\ShellContributor;
use Kinetis\Vector\Http\ShellContributorInterface;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\Vector\Widgets\VectorDashboard;
use Kinetis\Vector\Exception\ResourceNotFoundException;
use ReflectionClass;

/**
 * The descriptor as a section of the AOT cache: compiled by `kinetis
 * build` from every #[Resource] class and the ORM's own metadata, and
 * bound on AppScope before any package bootstrap runs. Immutable and
 * request-neutral; the per-user permission overlay is computed elsewhere.
 *
 * {@see VERSION} is the descriptor's version: the UI refuses a descriptor
 * of another, and a cache compiled for another is rebuilt.
 */
final readonly class VectorRegistry implements CacheableDiscoveryInterface
{
    public const int VERSION = 6;


    /** @var array<string, ResourceDescriptor> */
    private array $descriptors;

    /** @var array<string, SettingsDescriptor> */
    private array $settings;

    /**
     * @param array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, contributors: array<string, class-string<ShellContributorInterface>>} $data
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
     * section, which the context compiles once for every consumer.
     */
    #[\Override]
    public static function compile(DiscoveryContext $context): array
    {
        $classes = [];
        $groups = [];
        $dashboards = [];
        $contributors = [];

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

            // a contributor may also be something else (a settings group, say): it is looked at on its own
            if ($reflection->getAttributes(ShellContributor::class) !== []) {
                $contributors[] = $class;
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
            'contributors' => ContributorCompiler::compile($contributors),
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

        // compile() always writes it; a hand-built registry may leave it out
        $data['contributors'] ??= [];

        if (!is_array($data['contributors'])) {
            throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a malformed shell contributor map');
        }

        foreach ($data['contributors'] as $name => $class) {
            if (!is_string($name) || preg_match(ShellContributor::NAME, $name) !== 1 || !is_string($class)) {
                throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a malformed shell contributor entry');
            }
        }

        foreach ($data['resources'] as $slug => $resource) {
            if (!is_string($slug) || !is_array($resource) || ($resource['slug'] ?? null) !== $slug || !is_array($resource['fields'] ?? null)) {
                throw InvalidCacheArtifactException::malformedEntry('VectorRegistry', 'a malformed resource entry');
            }
        }

        /** @var array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, contributors: array<string, class-string<ShellContributorInterface>>} $data */
        return new self($data);
    }

    /**
     * The #[ShellContributor] classes, keyed by the capability name each
     * publishes under, ordered by name.
     *
     * @return array<string, class-string<ShellContributorInterface>>
     */
    public function contributors(): array
    {
        return $this->data['contributors'];
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

    /** @return array{version: int, resources: array<string, array<string, mixed>>, settings: array<string, array<string, mixed>>, dashboards: array<string, array<string, mixed>>, contributors: array<string, class-string<ShellContributorInterface>>} */
    public function toArray(): array
    {
        return $this->data;
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
