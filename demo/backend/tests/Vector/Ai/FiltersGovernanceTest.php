<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Vector\UserPolicy;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\ResourcePolicy;

/**
 * What the model is sent, read off the requests the fake provider
 * recorded: never a password, file or image field, a field the user's
 * viewField hides (here the users' `createdAt`), or one that opts out
 * (the demo's users `email`); never anything of a resource that opts out
 * (the demo's profiles, 403). With the AI settings on `allowlist`, only a
 * resource and the fields that opt in are (the demo's products).
 */
final class FiltersGovernanceTest extends FiltersTestCase
{
    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(UserPolicy::class, new HidingCreatedAtPolicy());
    }

    public function testAPasswordAHiddenAndAnOptedOutFieldAreNeverSent(): void
    {
        $this->script(['the admins' => self::answer([['field' => 'isAdmin', 'op' => 'eq', 'value' => 'true']])]);

        $this->ask('users', 'the admins')->assertOk()->assertJsonPath('filter', ['isAdmin' => ['eq' => 'true']]);

        self::assertCount(1, $this->calls());
        $sent = self::sent($this->calls()[0]);
        self::assertStringContainsString('"isAdmin"', $sent);
        self::assertStringContainsString('"name"', $sent, 'a field that is neither kept back nor filterable is still described');

        foreach (['passwordHash', 'New password', 'email', 'Email', 'createdAt', 'Created at'] as $kept) {
            self::assertStringNotContainsString($kept, $sent, "{$kept} reached the model");
        }

        self::assertSame(['isAdmin'], $this->calls()[0]->structuredSchema['properties']['conditions']['items']['properties']['field']['enum']);
    }

    public function testAnImageFieldIsNeverSent(): void
    {
        $this->script(['featured products' => self::answer([['field' => 'isFeatured', 'op' => 'eq', 'value' => 'true']])]);

        $this->ask('products', 'featured products')->assertOk();

        $sent = self::sent($this->calls()[0]);
        self::assertStringContainsString('"isFeatured"', $sent);
        self::assertStringNotContainsString('imageKey', $sent);
    }

    public function testAFieldKeptFromTheModelCannotBeFilteredByGuessingIt(): void
    {
        $this->script(['users at example.com' => self::answer([['field' => 'email', 'op' => 'like', 'value' => 'example.com']])]);

        $failed = $this->ask('users', 'users at example.com')->assertStatus(422)->json();

        self::assertArrayNotHasKey('filter', $failed);
        self::assertCount(2, $this->calls());
        $repair = $this->calls()[1]->messages;
        $refusal = (string) $repair[array_key_last($repair)]->getContent();
        self::assertStringContainsString('use only isAdmin.', $refusal);
        self::assertStringNotContainsString('email', $refusal);
    }

    public function testAFilterableFieldKeptFromTheModelIsRefusedWithoutNamingIt(): void
    {
        // priceCents is filterable, but under the allow-list products send only what opts in, and it does not
        $this->settings(['dataDefault' => 'allowlist']);
        $guess = self::answer([['field' => 'priceCents', 'op' => 'gt', 'value' => '10000']]);
        $this->script(['products over 100' => $guess]);
        $this->ask('products', 'products over 100')->assertStatus(422);
        $first = $this->calls();
        self::assertCount(2, $first);
        $repair = $first[1]->messages;
        $refusal = (string) $repair[array_key_last($repair)]->getContent();

        // the repair turn names only the fields the model was told of
        self::assertStringContainsString('use only name, status, category, stock.', $refusal);
        self::assertStringNotContainsString('priceCents', $refusal);

        // the repair guesses again: 422, and the field is nowhere in the answer
        $this->script(['products over 100' => $guess, $refusal => $guess]);
        $failed = $this->ask('products', 'products over 100')->assertStatus(422);
        self::assertCount(2, $this->calls());
        self::assertStringNotContainsString('priceCents', $failed->body());
        self::assertArrayNotHasKey('filter', $failed->json());
    }

    public function testAnOptedOutResourceIsForbiddenAndNothingIsSent(): void
    {
        $this->script(['profiles named Ada' => self::answer([])]);

        $this->ask('profiles', 'profiles named Ada')->assertStatus(403)->assertJsonPath('error', 'Profiles is kept from AI.');

        self::assertSame([], $this->calls());
    }

    public function testTheAllowListSendsOnlyWhatOptsIn(): void
    {
        $this->settings(['dataDefault' => 'allowlist']);
        $this->script(['active products low on stock' => self::answer([
            ['field' => 'status', 'op' => 'eq', 'value' => 'active'],
            ['field' => 'stock', 'op' => 'lte', 'value' => '5'],
        ])]);

        // orders say nothing, so they are kept back; profiles opt out either way
        $this->ask('orders', 'active products low on stock')->assertStatus(403);
        $this->ask('profiles', 'active products low on stock')->assertStatus(403);
        $asked = $this->calls();
        self::assertSame([], $asked);

        $this->ask('products', 'active products low on stock')->assertOk()->assertJsonPath('filter', ['status' => ['eq' => 'active'], 'stock' => ['lte' => '5']]);

        $call = $this->calls()[0] ?? self::fail('the provider was not asked');
        self::assertSame(['category', 'name', 'status', 'stock'], self::toldFields($call));
        self::assertStringNotContainsString('priceCents', self::sent($call));

        // the default sends every field but those opting out
        $this->settings(['dataDefault' => 'send']);
        $this->script(['active products low on stock' => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'active']])]);
        $this->ask('products', 'active products low on stock')->assertOk();
        self::assertStringContainsString('"priceCents"', self::sent($this->calls()[0]));
    }

    /**
     * The names of the fields the call's first message told the model of,
     * sorted: its first block is a line of text, then the resource and the
     * current filter as JSON, then the instructions that follow.
     *
     * @return list<string>
     */
    private static function toldFields(\NeuronAI\Testing\RequestRecord $call): array
    {
        $blocks = array_values($call->messages[0]->getTextBlocks());
        $json = (string) strstr((string) strstr($blocks[0]->content, "\n"), "\nYour filter replaces the current one", true);
        $context = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($context);
        $names = array_map(static fn (array $f): string => (string) $f['name'], $context['resource']['fields'] ?? []);
        sort($names);

        return $names;
    }
}

/** The users' policy with `createdAt` hidden from everyone. */
final class HidingCreatedAtPolicy extends ResourcePolicy
{
    #[\Override]
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        return $field !== 'createdAt';
    }
}
