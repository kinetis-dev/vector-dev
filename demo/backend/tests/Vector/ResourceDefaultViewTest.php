<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * A shared view can be the default of everyone who has none of their
 * own: one per resource, only while shared, set where the policy's
 * setDefaultView() allows it.
 */
final class ResourceDefaultViewTest extends VectorTestCase
{
    private DefaultViewPolicy $policy;

    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new DefaultViewPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
    }

    public function testASharedViewBecomesEveryonesDefault(): void
    {
        $owner = ['Authorization' => 'Bearer ' . $this->issue('901', true)];
        $other = ['Authorization' => 'Bearer ' . $this->issue('902', true)];
        $ids = [];

        try {
            $first = $this->view($owner, 'Everyone A', shared: true, resourceDefault: true)->assertStatus(201)->json();
            self::assertIsArray($first);
            $ids[] = $first['data']['id'];
            self::assertTrue($first['data']['resourceDefault']);

            $seen = $this->views($other)[$first['data']['id']];
            self::assertTrue($seen['resourceDefault'], 'another admin sees it as everyone\'s default');
            self::assertFalse($seen['isDefault'], 'and not as a default of their own');

            // one per resource: a second one takes over
            $second = $this->view($owner, 'Everyone B', shared: true, resourceDefault: true)->assertStatus(201)->json();
            self::assertIsArray($second);
            $ids[] = $second['data']['id'];
            self::assertFalse($this->views($other)[$first['data']['id']]['resourceDefault']);
            self::assertTrue($this->views($other)[$second['data']['id']]['resourceDefault']);

            $this->view($owner, 'Private', shared: false, resourceDefault: true)->assertValidationError('resourceDefault');
        } finally {
            foreach ($ids as $id) {
                $this->client->delete("/vector/views/tags/{$id}", headers: $owner)->assertStatus(204);
            }
        }
    }

    public function testThePolicyDecidesWhoSetsIt(): void
    {
        $h = ['Authorization' => 'Bearer ' . $this->issue('903', true)];
        $this->policy->allowDefaultView = false;

        $schema = $this->client->get('/vector/schema/tags', headers: $h)->assertOk()->json();
        self::assertIsArray($schema);
        self::assertFalse($schema['permissions']['setDefaultView']);

        $this->view($h, 'Refused', shared: true, resourceDefault: true)->assertStatus(403);

        // a plain shared view is still anyone's to save
        $plain = $this->view($h, 'Plain', shared: true, resourceDefault: false)->assertStatus(201)->json();
        self::assertIsArray($plain);
        $this->client->delete("/vector/views/tags/{$plain['data']['id']}", headers: $h)->assertStatus(204);
    }

    /** @param array<string, string> $headers */
    private function view(array $headers, string $name, bool $shared, bool $resourceDefault): \Kinetis\Testing\TestResponse
    {
        return $this->client->post('/vector/views/tags', body: [
            'name' => $name . ' ' . bin2hex(random_bytes(2)),
            'shared' => $shared,
            'resourceDefault' => $resourceDefault,
            'state' => ['sort' => '-id'],
        ], headers: $headers);
    }

    /**
     * @param array<string, string> $headers
     * @return array<int, array<string, mixed>> by id
     */
    private function views(array $headers): array
    {
        $views = $this->client->get('/vector/views/tags', headers: $headers)->assertOk()->json();
        self::assertIsArray($views);

        return array_column($views['data'], null, 'id');
    }
}

/** Allows everything, except setting everyone's default view when the test switches it off. */
final class DefaultViewPolicy extends ResourcePolicy
{
    public bool $allowDefaultView = true;

    public function setDefaultView(CurrentUserInterface $user): bool
    {
        return $this->allowDefaultView;
    }
}
