<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Orm\Date;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Vector\Import\RowMapper;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Write\ValueConverter;
use PHPUnit\Framework\TestCase;

/**
 * An import row becomes the body a create or an update takes, and the
 * writer converts that body again: each cell must come out as the JSON
 * value its field accepts. No application, no database.
 */
final class ImportRowMapperTest extends TestCase
{
    public function testEveryParsedCellIsAValueTheWriterAcceptsAgain(): void
    {
        $converter = $this->converter();
        $agents = new ResourceDescriptor($this->data());
        $mapping = ['Joined' => 'joinedOn', 'Seen' => 'lastSeenAt', 'Active' => 'active', 'Name' => 'name'];

        $payload = (new RowMapper($converter))->payload($agents, $mapping, ['Joined' => '2026-03-04', 'Seen' => '2026-03-04T10:30:00+02:00', 'Active' => 'yes', 'Name' => 'Ada']);

        // a date-only cell once came out as a Date object, which the writer refused: "must be a date as YYYY-MM-DD."
        $joined = $converter->convert($agents->fields()['joinedOn'], $payload['joinedOn']);
        self::assertInstanceOf(Date::class, $joined, 'a date-only cell converts again as the Date its property holds');
        self::assertSame('2026-03-04', (string) $joined);

        self::assertSame(['joinedOn' => '2026-03-04', 'lastSeenAt' => '2026-03-04T08:30:00.000Z', 'active' => true, 'name' => 'Ada'], $payload);
    }

    private function converter(): ValueConverter
    {
        $registry = VectorRegistry::fromArray(['version' => VectorRegistry::VERSION, 'resources' => ['agents' => $this->data()], 'settings' => [], 'dashboards' => []]);
        $managers = EntityManagerRegistry::create(OrmFactoryRegistry::create([], MetadataRegistry::fromClasses([])));

        return new ValueConverter($registry, $managers, new RequestScope(new AppScope()));
    }

    /** @return array<string, mixed> a compiled resource with one field of each text-parsed type */
    private function data(): array
    {
        $field = static fn (string $name, string $type, string $valueType): array => ['name' => $name, 'type' => $type, 'valueType' => $valueType, 'column' => $name, 'nullable' => true, 'rules' => [], 'operators' => []];

        return [
            'slug' => 'agents',
            'primaryKey' => ['field' => 'id', 'column' => 'id', 'type' => 'int'],
            'fields' => [
                $field('id', 'id', 'int'),
                $field('joinedOn', 'date', 'date'),
                $field('lastSeenAt', 'datetime', 'timestamp'),
                $field('active', 'boolean', 'bool'),
                $field('name', 'string', 'string'),
            ],
            'columns' => ['id' => 'id', 'joinedOn' => 'joinedOn', 'lastSeenAt' => 'lastSeenAt', 'active' => 'active', 'name' => 'name'],
        ];
    }
}
