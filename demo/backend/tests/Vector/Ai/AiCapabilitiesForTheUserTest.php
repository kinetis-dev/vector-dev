<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\VectorAi\Auth\AiAccessInterface;
use Kinetis\VectorAi\Feature;
use Kinetis\VectorAi\Shell\AiShellContributor;

/**
 * capabilities.ai reuses what GET /vector/schema already decided (the
 * resources this user may list and the fields they see) so offering AI
 * asks no resource or field policy a second time; and it answers for the
 * user it is given, not for whoever made the request.
 */
final class AiCapabilitiesForTheUserTest extends AiTestCase
{
    /** The only user the access policy here lets use AI, besides the admins the schema is asked as. */
    private const string OTHER = 'test-capabilities-other';

    private CountingPolicy $policy;

    private ?RequestScope $scope = null;

    #[\Override]
    protected function aiConfig(): array
    {
        return ['VECTOR_AI_PROVIDERS' => 'test-fake', 'VECTOR_AI_TEST_FAKE_TYPE' => 'fake'];
    }

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $this->policy = new CountingPolicy();
        $app->instance(AllowAllPolicy::class, $this->policy);
        $app->instance(AiAccessInterface::class, new AllowsOnly(['1', self::OTHER]));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->scope?->dispose();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testOfferingAiAsksNoResourceOrFieldPolicyASecondTime(): void
    {
        // AI off for everyone: the contributor reads no resource
        $this->client->request('PUT', '/vector/settings/ai', body: ['dailyTokenBudgetPerUser' => 0], headers: $this->adminHeaders())->assertOk();
        $this->policy->reset();
        $off = $this->ai();
        $asked = [$this->policy->viewAny, $this->policy->viewField];
        self::assertFalse($off['filters']);

        // AI on: excluded and excludedFields are computed, from what the descriptor already decided
        $this->client->request('PUT', '/vector/settings/ai', body: ['dailyTokenBudgetPerUser' => 200000], headers: $this->adminHeaders())->assertOk();
        $this->policy->reset();
        $on = $this->ai();
        self::assertTrue($on['filters']);
        self::assertGreaterThan(0, $asked[0]);
        self::assertSame($asked, [$this->policy->viewAny, $this->policy->viewField], 'viewAny and viewField asked as often with AI offered as without');
    }

    public function testCapabilitiesAnswerForTheUserGivenNotTheRequestsUser(): void
    {
        $this->scope = $this->app->createRequestScope();
        $requester = new NamedUser('test-capabilities-requester');
        $this->scope->instance(CurrentUserInterface::class, $requester);
        $contributor = $this->scope->get(AiShellContributor::class);
        self::assertInstanceOf(AiShellContributor::class, $contributor);

        $theirs = $contributor->capabilities($requester);
        self::assertSame([false, false, false], [$theirs['filters'], $theirs['assistant'], $theirs['writing']], 'the requester may not use AI');

        $other = $contributor->capabilities(new NamedUser(self::OTHER));
        self::assertSame([true, true, true], [$other['filters'], $other['assistant'], $other['writing']], 'the user given may');

        // and the request's own user is still the requester
        self::assertSame($requester, $this->scope->get(CurrentUserInterface::class));
        self::assertSame([false, false, false], array_values(array_intersect_key($contributor->capabilities($requester), ['filters' => 0, 'assistant' => 0, 'writing' => 0])));
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        $ai = $this->client->get('/vector/schema', headers: $this->adminHeaders())->assertOk()->json()['capabilities']['ai'] ?? null;
        self::assertIsArray($ai);

        return $ai;
    }
}

/** Lets everything, counting each viewAny and viewField it is asked. */
final class CountingPolicy extends ResourcePolicy
{
    public int $viewAny = 0;

    public int $viewField = 0;

    public function reset(): void
    {
        $this->viewAny = 0;
        $this->viewField = 0;
    }

    #[\Override]
    public function viewAny(CurrentUserInterface $user): bool
    {
        $this->viewAny++;

        return true;
    }

    #[\Override]
    public function viewField(CurrentUserInterface $user, string $field): bool
    {
        $this->viewField++;

        return true;
    }
}

/** AI for the users named, and nobody else. */
final readonly class AllowsOnly implements AiAccessInterface
{
    /** @param list<string> $ids */
    public function __construct(private array $ids) {}

    #[\Override]
    public function canUse(CurrentUserInterface $user, Feature $feature): bool
    {
        return in_array((string) $user->id(), $this->ids, true);
    }

    #[\Override]
    public function canConfigure(CurrentUserInterface $user): bool
    {
        return in_array((string) $user->id(), $this->ids, true);
    }
}

/** A user known by id alone. */
final readonly class NamedUser implements CurrentUserInterface
{
    public function __construct(private string $id) {}

    #[\Override]
    public function id(): string
    {
        return $this->id;
    }
}
