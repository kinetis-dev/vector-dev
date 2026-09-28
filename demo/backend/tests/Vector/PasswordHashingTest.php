<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Domain\User;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Attributes\Resource;
use Kinetis\Vector\Exception\ResourceCompileException;
use Kinetis\Vector\Fields\Id;
use Kinetis\Vector\Fields\Password;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\VectorResource;

/**
 * A hashed password field: what is typed reaches the row as the hash
 * the application's bound hasher makes, an empty value keeps the stored
 * one, and the hash itself never appears in an answer or the audit trail.
 */
final class PasswordHashingTest extends VectorTestCase
{
    public function testWhatIsTypedIsStoredHashedAndNothingTypedKeepsTheHash(): void
    {
        $link = $this->link();
        $editor = (new Query($link))->table('users')->select('id', 'password_hash')->where('email', '=', 'editor@vector.local')->first();
        self::assertIsArray($editor);
        $id = (int) $editor['id'];

        try {
            $schema = $this->client->get('/vector/schema/users', headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($schema);
            $field = array_column($schema['fields'], null, 'name')['passwordHash'];
            self::assertTrue($field['props']['hashed']);
            self::assertArrayNotHasKey('hasher', $field['props'], 'the hasher is the server\'s');
            self::assertSame(['create', 'update'], $field['views']);

            $answer = $this->client->request('PATCH', "/vector/r/users/{$id}", body: ['passwordHash' => 'a-new-secret-1'], headers: $this->adminHeaders())->assertOk()->json();
            self::assertIsArray($answer);
            self::assertArrayNotHasKey('passwordHash', $answer['data']);
            $stored = $this->storedHash($link, $id);
            self::assertNotSame('a-new-secret-1', $stored, 'never stored as typed');
            self::assertTrue(password_verify('a-new-secret-1', $stored));

            // the trail never carries the field, let alone the hash: a password change is logged, not audited
            $trail = json_encode($this->client->get("/vector/r/users/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json(), JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('passwordHash', $trail);
            self::assertStringNotContainsString(json_encode($stored, JSON_THROW_ON_ERROR), $trail);

            // the edit form sends the field empty when nothing was typed
            $this->client->request('PATCH', "/vector/r/users/{$id}", body: ['passwordHash' => '', 'name' => 'Editor'], headers: $this->adminHeaders())->assertOk();
            self::assertTrue(password_verify('a-new-secret-1', $this->storedHash($link, $id)), 'the stored hash is untouched');

            $this->client->request('PATCH', "/vector/r/users/{$id}", body: ['passwordHash' => 'short'], headers: $this->adminHeaders())->assertValidationError('passwordHash');
            $this->client->request('PATCH', "/vector/r/users/{$id}", body: ['passwordHash' => 42], headers: $this->adminHeaders())->assertValidationError('passwordHash');
            self::assertTrue(password_verify('a-new-secret-1', $this->storedHash($link, $id)), 'the stored hash is untouched');
        } finally {
            (new Query($link))->table('users')->where('id', '=', $id)->update(['password_hash' => $editor['password_hash'], 'name' => 'Editor']);
        }
    }

    public function testAHasherMustImplementTheInterface(): void
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));
        $resource = new #[Resource(User::class, slug: 'hashed-users')] class extends VectorResource {
            public function fields(): array
            {
                // a mistaken resource naming a class that is no hasher
                /** @phpstan-ignore argument.type */
                return [Id::make(), Password::make('passwordHash')->hashed(\stdClass::class)];
            }
        };

        try {
            (new ResourceCompiler($metadata, [$resource::class]))->compile();
            self::fail('compiled with a hasher that is no hasher');
        } catch (ResourceCompileException $e) {
            self::assertStringContainsString('does not implement', $e->getMessage());
        }
    }

    /** The hash as the users table holds it now: read afresh each time, since the writer changes it. */
    private function storedHash(MysqlLink $link, int $id): string
    {
        $row = (new Query($link))->table('users')->select('password_hash')->where('id', '=', $id)->first();
        self::assertIsArray($row);

        return (string) $row['password_hash'];
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
