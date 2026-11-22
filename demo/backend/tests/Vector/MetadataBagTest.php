<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\Tag;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Cache\Exception\InvalidCacheArtifactException;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Color;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Text;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\VectorResource;
use PHPUnit\Framework\TestCase;

/**
 * The metadata bag: #[Resource(meta: ...)] and Field::meta() reach the
 * compiled and the published descriptor verbatim, survive a policy's
 * field hiding, and leave a resource that declares none exactly as it was.
 */
final class MetadataBagTest extends TestCase
{
    public function testMetaRoundTripsThroughTheCompilerAndThePublishedDescriptor(): void
    {
        $compiled = $this->compile(MetaTags::class)['meta-tags'];

        self::assertSame(['acme.audience' => 'staff', 'acme.levels' => [1, 2, null]], $compiled['meta']);
        $fields = array_column($compiled['fields'], null, 'name');
        self::assertSame(['acme.send' => false], $fields['name']['meta']);
        self::assertArrayNotHasKey('meta', $fields['color'], 'a field that declares no meta carries none');

        $descriptor = new ResourceDescriptor($compiled);
        $public = $descriptor->toArray();
        self::assertSame($compiled['meta'], $public['meta']);
        self::assertSame($compiled['meta'], $descriptor->meta());
        $publicFields = array_column($public['fields'], null, 'name');
        self::assertSame(['acme.send' => false], $publicFields['name']['meta']);

        $hidden = $descriptor->withoutFields(['color'])->toArray();
        $kept = array_column($hidden['fields'], null, 'name');
        self::assertArrayNotHasKey('color', $kept);
        self::assertSame(['acme.send' => false], $kept['name']['meta'], 'withoutFields() keeps the meta of the fields it keeps');
        self::assertSame($compiled['meta'], $hidden['meta']);
    }

    public function testAResourceWithoutMetaPublishesNoMetaKey(): void
    {
        $compiled = $this->compile(PlainTags::class)['plain-tags'];
        $public = (new ResourceDescriptor($compiled))->toArray();

        self::assertArrayNotHasKey('meta', $compiled);
        self::assertArrayNotHasKey('meta', $public);

        foreach ($public['fields'] as $field) {
            self::assertArrayNotHasKey('meta', $field);
        }
    }

    public function testTheDemoRegistryRoundTripsAndRefusesAVersionFiveCache(): void
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));
        self::assertSame(6, VectorRegistry::VERSION);
        self::assertSame($data, VectorRegistry::fromArray($data)->toArray());

        $old = $data;
        $old['version'] = 5;
        $this->expectException(InvalidCacheArtifactException::class);
        VectorRegistry::fromArray($old);
    }

    public function testAFieldRefusesMetaVectorCouldNotPublish(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('meta "acme.nested" must be a scalar, null or a list of those');
        Text::make('name')->meta('acme.nested', ['a' => 1]);
    }

    public function testAFieldRefusesAKeyThatIsNoDottedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Text::make('name')->meta('has space', true);
    }

    public function testAFieldRefusesAKeyWithATrailingNewline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('a meta key is a dotted name');
        Text::make('name')->meta("acme.key\n", true);
    }

    public function testTheCompilerRefusesMalformedResourceMeta(): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage('must be a scalar, null or a list of those');
        $this->compile(BadMetaTags::class);
    }

    /**
     * @param class-string ...$classes
     * @return array<string, array<string, mixed>>
     */
    private function compile(string ...$classes): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, array_values($classes)))->compile();
    }
}

#[Resource(Tag::class, slug: 'meta-tags', meta: ['acme.audience' => 'staff', 'acme.levels' => [1, 2, null]])]
final class MetaTags extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [Id::make('id'), Text::make('name')->meta('acme.send', false), Color::make('color')];
    }
}

#[Resource(Tag::class, slug: 'plain-tags')]
final class PlainTags extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [Id::make('id'), Text::make('name'), Color::make('color')];
    }
}

// @phpstan-ignore argument.type (the refusal under test)
#[Resource(Tag::class, slug: 'bad-meta-tags', meta: ['acme.nested' => ['a' => ['b' => 1]]])]
final class BadMetaTags extends VectorResource
{
    #[\Override]
    public function fields(): array
    {
        return [Id::make('id'), Text::make('name')];
    }
}
