<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Vector\Registry\ResourceDescriptor;
use ReflectionClass;
use ReflectionProperty;

/**
 * The default way a resource's values reach an entity: the entity is
 * allocated without its constructor — exactly as the ORM hydrates one —
 * and each field's property is written by reflection. Every mapped
 * property the resource does not administer keeps its declared default
 * or becomes null where nullable; the compiler already refused a
 * resource whose entity has a property this cannot satisfy.
 *
 * Holds only immutable reflection per class, so one instance serves a
 * worker; it never retains an entity.
 */
final class EntityWriter
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private array $properties = [];

    /** @param array<string, mixed> $values field name => converted value */
    public function instantiate(ResourceDescriptor $resource, array $values): object
    {
        $class = $resource->entityClass();
        $entity = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        foreach ($this->propertiesOf($class) as $name => $property) {
            if (array_key_exists($name, $values)) {
                $property->setValue($entity, $values[$name]);
            } elseif (!$property->hasDefaultValue() && !$property->isInitialized($entity)) {
                $mapped = $resource->entityProperties()[$name] ?? null;

                if ($mapped !== null && $mapped['nullable'] && in_array($mapped['kind'], ['column', 'belongsTo'], true)) {
                    $property->setValue($entity, null);
                }
                // an inverse or join collection stays uninitialized: the ORM admits that on a new entity
            }
        }

        return $entity;
    }

    /** @param array<string, mixed> $values field name => converted value */
    public function assign(ResourceDescriptor $resource, object $entity, array $values): void
    {
        $properties = $this->propertiesOf($resource->entityClass());

        foreach ($values as $name => $value) {
            if (isset($properties[$name])) {
                $properties[$name]->setValue($entity, $value);
            }
        }
    }

    public function stamp(ResourceDescriptor $resource, object $entity, string $property): void
    {
        $properties = $this->propertiesOf($resource->entityClass());

        if (isset($properties[$property])) {
            $properties[$property]->setValue($entity, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        }
    }

    public function read(object $entity, string $property): mixed
    {
        $properties = $this->propertiesOf($entity::class);

        return isset($properties[$property]) && $properties[$property]->isInitialized($entity)
            ? $properties[$property]->getValue($entity)
            : null;
    }

    /**
     * @param class-string $class
     * @return array<string, ReflectionProperty>
     */
    private function propertiesOf(string $class): array
    {
        if (!isset($this->properties[$class])) {
            $properties = [];

            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if (!$property->isStatic()) {
                    $properties[$property->getName()] = $property;
                }
            }

            $this->properties[$class] = $properties;
        }

        return $this->properties[$class];
    }
}
