<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Auth\AllowAllPolicy;
use Kinetis\Vector\Auth\ResourcePolicy;
use Kinetis\Vector\Query\ResourceService;

/**
 * A user who may list orders but not customers asks for orders of a
 * customer named in words: the lookup is not theirs to make, so the
 * answer is refused like any other unusable one — one repair turn, then
 * the admin's 422 — and never the target's 403 mid-translation. Every
 * call is still a usage row.
 */
final class FiltersLookupAccessTest extends FiltersTestCase
{
    private const string REFUSAL = '"customer" cannot be looked up; leave it out, or filter by another field.';

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $app->instance(AllowAllPolicy::class, new RelationTargetsUnlistablePolicy());
    }

    public function testALookupOnATargetTheUserMayNotListIsRepairedNotForbidden(): void
    {
        $lookup = self::answer([
            ['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => 'Acme']],
            ['field' => 'status', 'op' => 'eq', 'value' => 'paid'],
        ]);
        $this->script([
            'paid orders of acme' => $lookup,
            self::repair(self::REFUSAL) => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']], [], null, 'Paid orders.'),
        ]);

        $body = $this->ask('orders', 'paid orders of acme', actor: 4)->assertOk()->json();

        self::assertSame(['status' => ['eq' => 'paid']], $body['filter']);
        self::assertSame([], $body['unresolved']);
        self::assertCount(2, $this->calls(), 'the sentence, then one repair turn quoting the refusal');
        $messages = $this->calls()[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        self::assertSame(self::repair(self::REFUSAL), (string) $last->getContent(), 'the refusal names the field, never the target');
        self::assertSame(2, $this->usageRows(4));
    }

    public function testALookupRefusedTwiceIsTheAdmins422(): void
    {
        $lookup = self::answer([['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => 'Acme']]]);
        $this->script(['orders of acme' => $lookup, self::repair(self::REFUSAL) => $lookup]);

        $this->ask('orders', 'orders of acme', actor: 5)->assertStatus(422)->assertJsonPath('error', self::REFUSAL);

        self::assertCount(2, $this->calls());
        self::assertSame(2, $this->usageRows(5), 'one row per call, the refused ones too');
    }

    private function usageRows(int $actor): int
    {
        return $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . str_pad((string) $actor, 2, '0', STR_PAD_LEFT))->count();
    }
}

/**
 * Lets everyone list every resource, except when the list asked for is a
 * relation's choices: as if the relation's target were a resource this
 * user may not list. The demo's relations all lead to resources under the
 * same allow-all policy, so this is where the two can be told apart.
 */
final class RelationTargetsUnlistablePolicy extends ResourcePolicy
{
    #[\Override]
    public function viewAny(CurrentUserInterface $user): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === ResourceService::class && $frame['function'] === 'options') {
                return false;
            }
        }

        return true;
    }
}
