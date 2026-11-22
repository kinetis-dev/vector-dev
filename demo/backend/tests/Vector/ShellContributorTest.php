<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Attributes\ShellContributor;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Http\ShellContributorInterface;
use Kinetis\Vector\Registry\ContributorCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A #[ShellContributor] publishes its own object under
 * `capabilities.<name>` of GET /vector/schema, computed per user from the
 * request scope; a name that is malformed, taken twice or one of the
 * built-in capability keys stops the compile.
 */
final class ShellContributorTest extends VectorTestCase
{
    /** The demo's registry, with the contributors under test added: the test application runs on it. */
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));
        $data['contributors'] = ContributorCompiler::compile([AcmeContributor::class, EmptyContributor::class]);
        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    public function testTheContributorsCapabilitiesArePublishedPerUser(): void
    {
        $admin = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();
        $editor = $this->client->get('/vector/schema', headers: ['Authorization' => 'Bearer ' . $this->issue('2', true)])->assertOk()->json();
        self::assertIsArray($admin);
        self::assertIsArray($editor);

        self::assertSame(['user' => '1', 'tools' => ['a', 'b']], $admin['capabilities']['acme']);
        self::assertNotSame($admin['capabilities']['acme']['user'], $editor['capabilities']['acme']['user'], 'computed for the user asking, on every request');
        self::assertSame([], $admin['capabilities']['empty'], 'an empty answer is still published, so its presence says the package is there');
        self::assertTrue(is_bool($admin['capabilities']['queue']), 'the built-in capabilities are untouched');

        $raw = $this->client->get('/vector/schema', headers: $this->adminHeaders())->body();
        self::assertStringContainsString('"empty":{}', $raw, 'an empty answer is a JSON object');
    }

    public function testTheDemoRegistryHasNoContributorOfItsOwn(): void
    {
        $data = VectorRegistry::compile(new DiscoveryContext(dirname(__DIR__, 2)));

        self::assertArrayHasKey('contributors', $data);
        self::assertSame([], array_diff_key($data['contributors'], ['ai' => true]), 'only an installed package contributes');
    }

    /** @param list<class-string> $classes */
    #[DataProvider('refusals')]
    public function testAContributorTheCompilerRefuses(array $classes, string $message): void
    {
        $this->expectException(ResourceCompileException::class);
        $this->expectExceptionMessage($message);

        ContributorCompiler::compile($classes);
    }

    /** @return iterable<string, array{0: list<class-string>, 1: string}> */
    public static function refusals(): iterable
    {
        yield 'a built-in capability' => [[QueuesContributor::class], '"queues" is a built-in capability'];
        yield 'two of one name' => [[AcmeContributor::class, OtherAcmeContributor::class], '"acme" is already taken by ' . AcmeContributor::class];
        yield 'a malformed name' => [[MalformedContributor::class], 'name "Acme_Tools" is not lower-case letters'];
        yield 'not a contributor' => [[NotAContributor::class], 'is not a concrete ' . ShellContributorInterface::class];
    }

    public function testTheBuiltInKeysAreTheOnesTheSchemaPublishes(): void
    {
        $schema = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($schema);

        self::assertSame([], array_diff(array_keys($schema['capabilities']), [...ContributorCompiler::BUILT_IN, 'acme', 'empty']));
    }
}

#[ShellContributor(name: 'acme')]
final readonly class AcmeContributor implements ShellContributorInterface
{
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return ['user' => (string) $user->id(), 'tools' => ['a', 'b']];
    }
}

#[ShellContributor(name: 'empty')]
final readonly class EmptyContributor implements ShellContributorInterface
{
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return [];
    }
}

#[ShellContributor(name: 'acme')]
final readonly class OtherAcmeContributor implements ShellContributorInterface
{
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return [];
    }
}

#[ShellContributor(name: 'queues')]
final readonly class QueuesContributor implements ShellContributorInterface
{
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return [];
    }
}

#[ShellContributor(name: 'Acme_Tools')]
final readonly class MalformedContributor implements ShellContributorInterface
{
    #[\Override]
    public function capabilities(CurrentUserInterface $user): array
    {
        return [];
    }
}

#[ShellContributor(name: 'plain')]
final readonly class NotAContributor
{
}
