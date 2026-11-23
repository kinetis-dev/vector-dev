<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use Kinetis\VectorAi\Filters\FilterTranslator;

/**
 * POST /vector/ai/filters/{resource}: the model's answer becomes the
 * list's own grammar, which the list then applies; an answer the list
 * refuses gets one repair turn and is then the admin's 422; a relation
 * named in words is looked up under the user's scope; and every call,
 * the repair turn too, is a usage row.
 */
final class FiltersTranslationTest extends FiltersTestCase
{
    /** Customers of the tests' own, with names no seeded customer has. */
    private const array CUSTOMERS = ['Zqxv Acme GmbH', 'Zqxv Acme Ltd', 'Zqxv Solo Corp'];

    #[\Override]
    protected function tearDown(): void
    {
        $this->shop()->table('customers')->whereIn('name', self::CUSTOMERS)->delete();
        parent::tearDown();
    }

    public function testAValidTranslationIsReturnedAndTheListAppliesIt(): void
    {
        $this->script(['paid orders over 500, or pending ones' => self::answer(
            [['field' => 'totalCents', 'op' => 'gt', 'value' => '50000']],
            [[[['field' => 'status', 'op' => 'eq', 'value' => 'paid']], [['field' => 'status', 'op' => 'in', 'value' => ['pending', 'cancelled']]]]],
            null,
            'Orders above 500.00 that are paid, pending or cancelled.',
        )]);

        $body = $this->ask('orders', 'paid orders over 500, or pending ones', ['timezone' => 'Europe/Berlin'])->assertOk()->json();

        self::assertSame([
            'filter' => ['totalCents' => ['gt' => '50000']],
            'groups' => [[['status' => ['eq' => 'paid']], ['status' => ['in' => 'pending,cancelled']]]],
            'q' => null,
            'explanation' => 'Orders above 500.00 that are paid, pending or cancelled.',
            'unresolved' => [],
        ], $body);

        // asked once, structured, with the date and the zone the admin reads it in
        self::assertCount(1, $this->calls());
        self::assertSame('structured', $this->calls()[0]->method);
        self::assertStringContainsString('in Europe/Berlin; write absolute dates.', (string) $this->calls()[0]->systemPrompt?->getContent());

        // the list takes it as the page sends it: the conditions, and the one group as `or`
        $page = $this->client->get('/vector/r/orders', query: ['perPage' => 100, 'filter' => [...$body['filter'], 'or' => $body['groups'][0]]], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($page);
        $expected = $this->shop()->table('orders')->where('total_cents', '>', 50000)->whereIn('status', ['paid', 'pending', 'cancelled'])->count();
        self::assertGreaterThan(0, $expected);
        self::assertCount(min(100, $expected), $page['data']);

        foreach ($page['data'] as $order) {
            self::assertGreaterThan(50000, $order['totalCents']);
            self::assertContains($order['status'], ['paid', 'pending', 'cancelled']);
        }
    }

    public function testReasoningAModelWritesInlineBeforeItsAnswerIsTakenOutBeforeItIsRead(): void
    {
        $answer = json_encode(self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']], [], null, 'Paid orders.'), JSON_THROW_ON_ERROR);
        $this->script(['the paid orders' => "<thinking>They mean status {\"paid\"}.</thinking>\n" . $answer]);

        $body = $this->ask('orders', 'the paid orders')->assertOk()->json();

        self::assertSame(['status' => ['eq' => 'paid']], $body['filter']);
        self::assertSame('Paid orders.', $body['explanation']);
        self::assertCount(1, $this->calls(), 'read on the first answer, with no repair turn');
    }

    public function testAnInventedFieldGetsOneRepairTurnQuotingTheRefusal(): void
    {
        // first, find the refusal the model is shown: the repair turn is not scripted yet
        $this->script(['orders of VIP customers' => self::answer([['field' => 'vipLevel', 'op' => 'eq', 'value' => 'gold']])]);
        $this->ask('orders', 'orders of VIP customers')->assertStatus(422);
        $refusal = $this->repairText();
        self::assertStringContainsString('a condition names a field this filter cannot use; use only ', $refusal);
        self::assertStringNotContainsString('vipLevel', $refusal);

        // a repair that holds is the answer, and the invented field is nowhere in it
        $this->script([
            'orders of VIP customers' => self::answer([['field' => 'vipLevel', 'op' => 'eq', 'value' => 'gold']]),
            $refusal => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']], [], null, 'Paid orders.'),
        ]);
        $response = $this->ask('orders', 'orders of VIP customers')->assertOk();
        self::assertSame(['status' => ['eq' => 'paid']], $response->json()['filter']);
        self::assertStringNotContainsString('vipLevel', $response->body());
        self::assertCount(2, $this->calls(), 'one repair turn');
        self::assertCount(3, $this->calls()[1]->messages, 'the sentence, the refused answer, the refusal');
    }

    public function testAnOperatorTheFieldDoesNotAdmitFailsAfterOneRepair(): void
    {
        $this->script(['orders whose status is above paid' => self::answer([['field' => 'status', 'op' => 'gt', 'value' => 'paid']])]);
        $this->ask('orders', 'orders whose status is above paid')->assertStatus(422);
        $refusal = $this->repairText();
        self::assertStringContainsString('"status" does not admit the operator "gt"', $refusal);

        // the repair fails too: 422 with the list's own message, and no filter at all
        $this->script([
            'orders whose status is above paid' => self::answer([['field' => 'status', 'op' => 'gt', 'value' => 'paid']]),
            $refusal => self::answer([['field' => 'vipLevel', 'op' => 'eq', 'value' => 'gold'], ['field' => 'status', 'op' => 'eq', 'value' => 'paid']]),
        ]);
        $failed = $this->ask('orders', 'orders whose status is above paid')->assertStatus(422);
        self::assertStringContainsString('a condition names a field this filter cannot use', (string) ($failed->json()['error'] ?? ''));
        self::assertStringNotContainsString('vipLevel', $failed->body());
        self::assertArrayNotHasKey('filter', $failed->json());
        self::assertCount(2, $this->calls(), 'exactly one repair turn');
    }

    public function testALookupResolvesToOneIdOffersSeveralAndRefusesNone(): void
    {
        foreach (self::CUSTOMERS as $i => $name) {
            $this->shop()->table('customers')->insert(['email' => "zqxv{$i}@ai-filters.test", 'name' => $name, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }

        $ids = [];

        foreach ($this->shop()->table('customers')->select('id', 'name')->whereIn('name', self::CUSTOMERS)->get() as $row) {
            $ids[(string) $row['name']] = (int) $row['id'];
        }

        $lookup = static fn (string $words): array => self::answer([
            ['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => $words]],
            ['field' => 'status', 'op' => 'eq', 'value' => 'paid'],
        ]);
        $this->script([
            'paid orders of zqxv acme' => $lookup('Zqxv Acme'),
            'paid orders of zqxv solo' => $lookup('Zqxv Solo'),
            'paid orders of zqxv nobody' => $lookup('Zqxv Nobody'),
        ]);

        $several = $this->ask('orders', 'paid orders of zqxv acme')->assertOk()->json();
        self::assertSame(['status' => ['eq' => 'paid']], $several['filter'], 'the ambiguous condition waits for a pick');
        self::assertSame([[
            'field' => 'customer',
            'op' => 'eq',
            'text' => 'Zqxv Acme',
            'candidates' => [['id' => $ids['Zqxv Acme GmbH'], 'label' => 'Zqxv Acme GmbH'], ['id' => $ids['Zqxv Acme Ltd'], 'label' => 'Zqxv Acme Ltd']],
        ]], $several['unresolved']);

        $one = $this->ask('orders', 'paid orders of zqxv solo')->assertOk()->json();
        self::assertSame(['customer' => ['eq' => (string) $ids['Zqxv Solo Corp']], 'status' => ['eq' => 'paid']], $one['filter']);
        self::assertSame([], $one['unresolved']);

        $none = $this->ask('orders', 'paid orders of zqxv nobody')->assertStatus(422)->json();
        self::assertSame('Customer: nothing matches "Zqxv Nobody".', $none['error']);
    }

    public function testTwoAmbiguousLookupsOnOneFieldAndOperatorAreADuplicate(): void
    {
        foreach (self::CUSTOMERS as $i => $name) {
            $this->shop()->table('customers')->insert(['email' => "zqxv{$i}@ai-filters.test", 'name' => $name, 'created_at' => gmdate('Y-m-d H:i:s')]);
        }

        $twice = self::answer([
            ['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => 'Zqxv Acme']],
            ['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => 'Zqxv']],
        ]);
        $this->script([
            'orders of zqxv acme or zqxv' => $twice,
            self::repair('"customer" has the operator "eq" twice in one object.') => self::answer([['field' => 'customer', 'op' => 'eq', 'value' => ['lookup' => 'Zqxv Acme']]]),
        ]);

        $body = $this->ask('orders', 'orders of zqxv acme or zqxv')->assertOk()->json();

        self::assertCount(2, $this->calls(), 'refused as a duplicate, like two written values, and repaired once');
        self::assertCount(1, $body['unresolved'], 'one choice per field and operator');
        self::assertSame(['customer', 'eq', 'Zqxv Acme'], [$body['unresolved'][0]['field'], $body['unresolved'][0]['op'], $body['unresolved'][0]['text']]);
    }

    public function testAListValueWithACommaIsRefusedNotSplit(): void
    {
        $refusal = 'a value in the list for "status" contains a comma, which a list filter cannot hold; leave that value out, or filter "status" another way.';
        $this->script([
            'paid orders, or pending and cancelled ones' => self::answer([['field' => 'status', 'op' => 'in', 'value' => ['paid', 'pending, cancelled']]]),
            self::repair($refusal) => self::answer([['field' => 'status', 'op' => 'in', 'value' => ['paid', 'pending']]]),
        ]);

        $body = $this->ask('orders', 'paid orders, or pending and cancelled ones')->assertOk()->json();

        self::assertCount(2, $this->calls(), 'the comma was refused, with one repair turn');
        self::assertSame(['status' => ['in' => 'paid,pending']], $body['filter']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function unusableAnswers(): iterable
    {
        $paid = ['field' => 'status', 'op' => 'eq', 'value' => 'paid'];

        yield 'a condition that is no object' => [['conditions' => ['status = paid'], 'anyOf' => [], 'q' => null, 'explanation' => 'x'], 'each condition must be an object with field, op and value.'];
        yield 'a condition without its value' => [self::answer([['field' => 'status', 'op' => 'eq']]), 'each condition must be an object with field, op and value.'];
        yield 'a number for a value' => [self::answer([['field' => 'status', 'op' => 'eq', 'value' => 3]]), 'the value of "status" must be a string, a list of strings or {"lookup": words}.'];
        yield 'a list holding a number' => [self::answer([['field' => 'status', 'op' => 'in', 'value' => ['paid', 3]]]), 'the value of "status" must be a string, a list of strings or {"lookup": words}.'];
        yield 'an object without a lookup' => [self::answer([['field' => 'status', 'op' => 'eq', 'value' => ['words' => 'paid']]]), 'the value of "status" must be a string, a list of strings or {"lookup": words}.'];
        yield 'a lookup on a field that is no relation' => [self::answer([['field' => 'status', 'op' => 'eq', 'value' => ['lookup' => 'paid']]]), '"status" is not a relation: write its value, not a lookup.'];
        yield 'an empty anyOf alternative' => [self::answer([$paid], [[[]]]), 'every anyOf alternative needs at least one condition.'];
        yield 'an empty alternative after a valid one' => [self::answer([], [[[$paid], []]]), 'every anyOf alternative needs at least one condition.'];
        yield 'an anyOf group that is no list' => [['conditions' => [], 'anyOf' => [['status' => 'paid']], 'q' => null, 'explanation' => 'x'], 'each anyOf group must be a list.'];
        yield 'an alternative that is no list' => [['conditions' => [], 'anyOf' => [['paid']], 'q' => null, 'explanation' => 'x'], 'each anyOf alternative must be a list.'];
        yield 'conditions that are no list' => [['conditions' => ['status' => 'paid'], 'anyOf' => [], 'q' => null, 'explanation' => 'x'], 'conditions must be a list.'];
        yield 'a q that is no string' => [['conditions' => [$paid], 'anyOf' => [], 'q' => 5, 'explanation' => 'x'], 'q must be a string or null.'];
    }

    /** @param array<string, mixed> $answer */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableAnswers')]
    public function testAnUnusableAnswerGetsOneRepairTurnNamingWhy(array $answer, string $refusal): void
    {
        $this->script(['paid orders' => $answer]);

        $this->ask('orders', 'paid orders')->assertStatus(422);

        self::assertSame(self::repair($refusal), $this->repairText());
    }

    public function testAnAnyOfOfSeveralAlternativesIsAGroupAndAnEmptyGroupIsNone(): void
    {
        $this->script(['paid or pending orders' => self::answer(
            [['field' => 'placedAt', 'op' => 'between', 'value' => '2026-11-21T00:00:00+02:00,2026-11-21T23:59:59+02:00']],
            [
                [[['field' => 'status', 'op' => 'eq', 'value' => 'paid']], [['field' => 'status', 'op' => 'eq', 'value' => 'pending']]],
                [],
            ],
            '  ',
        )]);

        $body = $this->ask('orders', 'paid or pending orders')->assertOk()->json();

        self::assertSame(['placedAt' => ['between' => '2026-11-21T00:00:00+02:00,2026-11-21T23:59:59+02:00']], $body['filter']);
        self::assertSame([[['status' => ['eq' => 'paid']], ['status' => ['eq' => 'pending']]]], $body['groups']);
        self::assertNull($body['q']);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function zones(): iterable
    {
        yield 'an IANA zone' => ['America/New_York', 'America/New_York'];
        yield 'no such zone' => ['Mars/Olympus', 'UTC'];
        yield 'an offset' => ['+02:00', 'UTC'];
        yield 'an offset no zone has' => ['+99:00', 'UTC'];
        yield 'an abbreviation' => ['CET', 'UTC'];
        yield 'a NUL byte' => ["Europe/Berlin\0", 'UTC'];
        yield 'not a string' => [120, 'UTC'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('zones')]
    public function testTheSentenceIsReadInAnIanaZoneElseUtc(mixed $timezone, string $read): void
    {
        $this->script(['paid orders' => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']])]);

        $this->ask('orders', 'paid orders', ['timezone' => $timezone])->assertOk();

        $prompt = (string) $this->calls()[0]->systemPrompt?->getContent();
        self::assertStringContainsString(" in {$read}; write absolute dates.", $prompt);

        // a day the admin names is theirs: the model is given the zone's offset to write it with
        $now = new \DateTimeImmutable('now', new \DateTimeZone($read));
        self::assertStringContainsString("one in {$read}, not in UTC", $prompt);
        self::assertStringContainsString("(now {$now->format('P')}), such as {$now->format('Y-m-d')}T00:00:00{$now->format('P')} for the start of today.", $prompt);
    }

    public function testAnAnyOfOfOneAlternativeJoinsTheConditions(): void
    {
        $this->script(["today's orders" => self::answer(
            [['field' => 'status', 'op' => 'eq', 'value' => 'paid']],
            [[[['field' => 'placedAt', 'op' => 'between', 'value' => '2026-11-21T00:00:00+02:00,2026-11-21T23:59:59+02:00']]]],
        )]);

        $body = $this->ask('orders', "today's orders")->assertOk()->json();

        self::assertSame(['status' => ['eq' => 'paid'], 'placedAt' => ['between' => '2026-11-21T00:00:00+02:00,2026-11-21T23:59:59+02:00']], $body['filter']);
        self::assertSame([], $body['groups']);
    }

    public function testAnAnyOfOfOneAlternativeThatWouldReplaceAConditionStaysAGroup(): void
    {
        $this->script(['paid, or paid' => self::answer(
            [['field' => 'status', 'op' => 'eq', 'value' => 'paid']],
            [[[['field' => 'status', 'op' => 'eq', 'value' => 'pending']]]],
        )]);

        $body = $this->ask('orders', 'paid, or paid')->assertOk()->json();

        self::assertSame(['status' => ['eq' => 'paid']], $body['filter']);
        self::assertSame([[['status' => ['eq' => 'pending']]]], $body['groups']);
    }

    public function testABodyOverSixteenKibibytesIsRefusedBeforeTheModelIsAsked(): void
    {
        $this->ask('orders', 'paid orders', ['current' => ['filter' => ['status' => ['eq' => str_repeat('x', 16384)]]]])->assertStatus(413);

        self::assertSame([], $this->calls());
    }

    public function testEveryCallIsAUsageRowTheRepairTurnToo(): void
    {
        $this->script(['orders of VIP customers' => self::answer([['field' => 'vipLevel', 'op' => 'eq', 'value' => 'gold']])]);
        $this->ask('orders', 'orders of VIP customers', actor: 3)->assertStatus(422);
        $this->script([
            'orders of VIP customers' => self::answer([['field' => 'vipLevel', 'op' => 'eq', 'value' => 'gold']]),
            $this->repairText() => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']]),
        ]);
        $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . '03')->delete();

        $this->ask('orders', 'orders of VIP customers', actor: 3)->assertOk();

        $rows = $this->vectorQuery()->table('vector_ai_usage')->where('actor_id', '=', self::ACTOR_PREFIX . '03')->orderBy('id', 'ASC')->get();
        self::assertCount(2, $rows, 'the first call and the repair turn');

        foreach ($rows as $row) {
            self::assertSame('filters', $row['feature']);
            self::assertSame('test-fake', $row['provider']);
            self::assertSame('fake', $row['model']);
            self::assertSame('answered', $row['outcome']);
            self::assertGreaterThan(0, (int) $row['input_tokens']);
            self::assertGreaterThan(0, (int) $row['output_tokens']);
        }
    }

    public function testTheCurrentFilterIsContextCutToWhatTheModelMaySee(): void
    {
        $this->script(['only the paid ones' => self::answer([['field' => 'status', 'op' => 'eq', 'value' => 'paid']])]);

        $this->ask('users', 'only the paid ones', ['current' => ['filter' => ['isAdmin' => ['eq' => 'true'], 'email' => ['like' => 'secret-part']]]])->assertStatus(422);

        $sent = self::sent($this->calls()[0]);
        self::assertStringContainsString('"isAdmin":{"eq":"true"}', $sent);
        self::assertStringNotContainsString('secret-part', $sent, 'a condition on a field kept from the model is dropped');
    }

    /** The text of the last repair turn the provider was sent. */
    private function repairText(): string
    {
        $calls = $this->calls();
        self::assertCount(2, $calls, 'the sentence, then one repair turn');
        $messages = $calls[1]->messages;
        $last = end($messages);
        self::assertNotFalse($last);
        $text = (string) $last->getContent();
        self::assertStringStartsWith(substr(FilterTranslator::REPAIR_PROMPT, 0, 24), $text);

        return $text;
    }
}
