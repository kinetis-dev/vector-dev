<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Orm\Date as Day;
use Kinetis\Orm\EntityManager;
use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Fields\Date;
use Kinetis\Vector\Fields\DateTime;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Testing\InteractsWithVector;
use Kinetis\Vector\Validation\DateBounds;

/**
 * minDate() and maxDate() on Date and DateTime: a bound in the field's
 * own format or relative (`today`, `now`), resolved at every check, never
 * when the resource compiles; refused where declared when it is not one;
 * enforced on every write path. The demo's agents joined on or after
 * 2010-01-01 and never in the future.
 */
final class DateBoundsTest extends VectorTestCase
{
    use InteractsWithVector;
    use ValidatesInput;

    private ?EntityManager $supportManager = null;

    public function testTheCompiledRuleKeepsTodayForEveryCheckToResolve(): void
    {
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $joinedOn = $registry->resource('agents')->field('joinedOn') ?? self::fail('agents have no joinedOn');

        // a bound resolved at compile time would be a day here, frozen for the worker's life
        self::assertContains(['constraint' => DateBounds::class, 'args' => ['date', '2010-01-01', 'today']], $joinedOn['rules']);

        $rule = new DateBounds('date', '2010-01-01', 'today');
        $monday = new DateTimeImmutable('2026-11-23T12:00:00Z');
        $tuesday = new DateTimeImmutable('2026-11-24T12:00:00Z');

        self::assertSame('date_too_late', $rule->check('2026-11-25', $monday)?->code, 'the same rule, checked on Monday');
        self::assertNull($rule->check('2026-11-25', $tuesday), 'and on Tuesday, when the 25th is today somewhere');
        $early = $rule->check('2009-12-31', $tuesday);
        self::assertSame(['date_too_early', ['min' => '2010-01-01']], [$early?->code, $early?->parameters]);
        self::assertNull($rule->check('2010-01-01', $tuesday), 'the bound itself is inside');
    }

    public function testTodayIsTheCurrentDaySomewhereOnEarth(): void
    {
        // 11:00 UTC: already the 27th at UTC+14, still the 25th at UTC-12
        $now = new DateTimeImmutable('2026-11-26T11:00:00Z');
        $past = new DateBounds('date', null, 'today');
        $future = new DateBounds('date', 'today', null);

        self::assertNull($past->check('2026-11-27', $now));
        $late = $past->check('2026-11-28', $now);
        self::assertSame(['date_too_late', ['max' => 'today']], [$late?->code, $late?->parameters], 'the violation names the bound as declared');
        self::assertNull($future->check('2026-11-25', $now));
        self::assertSame('date_too_early', $future->check('2026-11-24', $now)?->code);
    }

    public function testNowIsTheInstantOfTheCheck(): void
    {
        $rule = new DateBounds('datetime', 'now', '2027-01-01T00:00:00.000Z');
        $now = new DateTimeImmutable('2026-11-26T12:00:00Z');

        self::assertNull($rule->check('2026-11-26T12:00:00.000Z', $now));
        self::assertSame('date_too_early', $rule->check('2026-11-26T11:59:59.000Z', $now)?->code);
        self::assertNull($rule->check('2026-11-26T11:59:59.000Z', $now->modify('-1 second')), 'a second earlier, it was not yet in the past');
        self::assertNull($rule->check('2027-01-01T01:00:00+01:00', $now), 'the maximum itself, written in another zone');
        self::assertSame('date_too_late', $rule->check('2027-01-01T00:00:00.001Z', $now)?->code);
        self::assertNull($rule->check(42, $now), 'a type mismatch is the converter\'s to report');
    }

    public function testBoundsAreNormalizedToTheFieldsOwnFormat(): void
    {
        $instant = new DateTimeImmutable('2026-03-01T02:30:00', new DateTimeZone('+05:00'));

        self::assertSame(['date', '2026-03-01', '2026-12-31'], Date::make('a')->minDate($instant)->maxDate(Day::fromString('2026-12-31'))->boundsRule('date')['args'] ?? null);
        self::assertSame(['datetime', '2026-02-28T21:30:00.000Z', 'now'], DateTime::make('a')->minDate($instant)->maxDate('now')->boundsRule('datetime')['args'] ?? null);
        self::assertSame(['datetime', '2026-01-01T09:00:00.000Z', null], DateTime::make('a')->minDate('2026-01-01T10:00:00+01:00')->boundsRule('datetime')['args'] ?? null);
        self::assertNull(Date::make('a')->boundsRule('date'), 'no bound, no rule');
        self::assertNotContains(DateBounds::class, array_column(Date::make('a')->toArray()['rules'], 'constraint'));
    }

    public function testABoundTheFieldDoesNotTakeIsRefusedWhereItIsDeclared(): void
    {
        $refusals = [
            'now on a date' => static fn () => Date::make('a')->minDate('now'),
            'an impossible day' => static fn () => Date::make('a')->maxDate('2026-02-30'),
            'a date-time on a date' => static fn () => Date::make('a')->maxDate('2026-02-03T10:00:00Z'),
            'today on a date-time' => static fn () => DateTime::make('a')->minDate('today'),
            'a day on a date-time' => static fn () => DateTime::make('a')->minDate(Day::fromString('2026-01-01')),
            'crossed days' => static fn () => Date::make('a')->minDate('2026-02-01')->maxDate('2026-01-31'),
            'crossed instants' => static fn () => DateTime::make('a')->maxDate('2026-01-01T00:00:00Z')->minDate('2026-01-01T00:00:01Z'),
        ];

        foreach ($refusals as $case => $declare) {
            try {
                $declare();
                self::fail("declared: {$case}");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('"a": ', $e->getMessage(), $case);
            }
        }
    }

    public function testLengthBoundsAreRefusedOnDates(): void
    {
        foreach (['min' => static fn () => Date::make('joinedOn')->min(1), 'max' => static fn () => DateTime::make('joinedOn')->max(10)] as $method => $call) {
            try {
                $call();
                self::fail("{$method}() was taken");
            } catch (\LogicException $e) {
                self::assertStringContainsString("{$method}Date()", $e->getMessage());
            }
        }
    }

    public function testBoundsHoldOnCreateUpdateBulkEditAndImport(): void
    {
        $agents = $this->vector($this->realAdminHeaders())->resource('agents');
        $tomorrow = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d');
        $teamId = (int) $this->support()->table('support_teams')->select('id')->orderBy('id', 'ASC')->value('id');
        $stamp = bin2hex(random_bytes(4));
        $agent = static fn (string $joined, string $suffix): array => ['name' => "Bounded {$suffix}", 'email' => "bounded-{$suffix}-{$stamp}@vector.local", 'team' => $teamId, 'role' => 'agent', 'isActive' => true, 'joinedOn' => $joined];
        $created = [];

        try {
            // create
            $agents->create($agent($tomorrow, 'late'))->assertInvalid('joinedOn', 'date_too_late');
            $agents->create($agent('2009-12-31', 'early'))->assertInvalid('joinedOn', 'date_too_early');
            $id = $agents->create($agent('2010-01-01', 'first'))->assertCreated()->record()['id'];
            $created[] = $id;

            // update
            $agents->update($id, ['joinedOn' => $tomorrow])->assertInvalid('joinedOn', 'date_too_late');

            // bulk edit
            $agents->batch('update', [$id], fields: ['joinedOn' => $tomorrow])->assertInvalid('joinedOn', 'date_too_late');
            self::assertSame('2010-01-01', (string) $this->support()->table('agents')->where('id', '=', $id)->value('joined_on'), 'nothing was written');

            // import: the row in the future fails, the other is created
            $csv = "name,email,team,role,isActive,joinedOn\r\n"
                . "Imported early,imported-ok-{$stamp}@vector.local,{$teamId},agent,true,2015-06-01\r\n"
                . "Imported late,imported-late-{$stamp}@vector.local,{$teamId},agent,true,{$tomorrow}\r\n";
            $mapping = ['name' => 'name', 'email' => 'email', 'team' => 'team', 'role' => 'role', 'isActive' => 'isActive', 'joinedOn' => 'joinedOn'];
            $queued = $this->upload('/vector/r/agents/import', $csv, ['mapping' => json_encode($mapping, JSON_THROW_ON_ERROR)])->assertStatus(202)->json();
            self::assertIsArray($queued);
            $result = $this->client->get("/vector/jobs/{$queued['jobId']}", headers: $this->realAdminHeaders())->assertOk()->json()['data']['result'] ?? null;
            self::assertIsArray($result);
            self::assertSame([1, 1], [$result['created'] ?? null, $result['failed'] ?? null]);
            self::assertSame(0, $this->support()->table('agents')->where('email', '=', "imported-late-{$stamp}@vector.local")->count());
        } finally {
            $this->support()->table('agents')->where('email', 'LIKE', "%{$stamp}@vector.local")->delete();
        }
    }

    public function testAnActionsDateInputIsBoundedToo(): void
    {
        $fields = ['until' => ResourceCompiler::compileLoose(Date::make('until')->minDate('today'), [], 'an action')];

        self::assertSame([[['until'], 'date_too_early']], $this->fieldInput($fields, ['until' => '2001-01-01']));
        $later = (new DateTimeImmutable('+2 days', new DateTimeZone('UTC')))->format('Y-m-d');
        // an input with no property holds an instant: midnight UTC of the day
        self::assertEquals(['until' => new DateTimeImmutable("{$later}T00:00:00Z")], $this->fieldInput($fields, ['until' => $later]));
    }

    /** @param array<string, string> $fields */
    private function upload(string $uri, string $csv, array $fields = []): \Kinetis\Testing\TestResponse
    {
        $body = '';

        foreach ($fields as $name => $value) {
            $body .= "--vector\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        $body .= "--vector\r\nContent-Disposition: form-data; name=\"file\"; filename=\"agents.csv\"\r\nContent-Type: text/csv\r\n\r\n{$csv}\r\n--vector--\r\n";

        return $this->client->raw('POST', $uri, $body, ['Content-Type' => 'multipart/form-data; boundary=vector', ...$this->realAdminHeaders()]);
    }

    /** A builder on the support database, through a manager this test opens and closes. */
    private function support(): Query
    {
        if ($this->supportManager === null) {
            $factories = $this->app->get(OrmFactoryRegistry::class);
            self::assertInstanceOf(OrmFactoryRegistry::class, $factories);
            $this->supportManager = $factories->factory('support')->open();
        }

        return $this->supportManager->builder();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->supportManager?->close();
        $this->supportManager = null;
        parent::tearDown();
    }
}
