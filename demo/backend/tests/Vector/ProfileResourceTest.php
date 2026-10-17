<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/**
 * Profiles and users are two resources over one table: each keeps its
 * own fields, policy and audit trail, keyed by its own slug.
 */
final class ProfileResourceTest extends VectorTestCase
{
    public function testAProfileIsTheSameRowUnderItsOwnFieldsPolicyAndTrail(): void
    {
        $link = $this->link();
        $editor = (new Query($link))->table('users')->select('id', 'name')->where('email', '=', 'editor@vector.local')->first();
        self::assertIsArray($editor);
        $id = (int) $editor['id'];
        $renamed = 'Profile ' . bin2hex(random_bytes(4));

        try {
            $schema = $this->client->get('/vector/schema/profiles', headers: $this->adminHeaders())
                ->assertOk()
                ->assertJsonPath('permissions.create', false)
                ->json();
            self::assertIsArray($schema);
            self::assertSame(['id', 'name', 'email', 'createdAt'], array_column($schema['fields'], 'name'), 'neither the admin flag nor the password');

            $this->client->request('PATCH', "/vector/r/profiles/{$id}", body: ['name' => $renamed], headers: $this->adminHeaders())
                ->assertOk()
                ->assertJsonPath('data.name', $renamed);
            $this->client->get("/vector/r/users/{$id}", headers: $this->adminHeaders())->assertOk()->assertJsonPath('data.name', $renamed);

            $this->client->delete("/vector/r/profiles/{$id}", headers: $this->adminHeaders())->assertStatus(403);
            self::assertNotNull((new Query($link))->table('users')->where('id', '=', $id)->first(), 'deleting belongs to users');

            $profileTrail = json_encode($this->client->get("/vector/r/profiles/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json(), JSON_THROW_ON_ERROR);
            $userTrail = json_encode($this->client->get("/vector/r/users/{$id}/audit", headers: $this->adminHeaders())->assertOk()->json(), JSON_THROW_ON_ERROR);
            self::assertStringContainsString($renamed, $profileTrail);
            self::assertStringNotContainsString($renamed, $userTrail, 'the trail is the resource\'s, by slug');
        } finally {
            (new Query($link))->table('users')->where('id', '=', $id)->update(['name' => $editor['name']]);
        }
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
