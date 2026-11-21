<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\VectorAi\Assistant\Assistant;
use Kinetis\VectorAi\Assistant\GovernedTool;
use Kinetis\VectorAi\Assistant\RunawayToolCallsException;
use Kinetis\VectorAi\Assistant\ToolBudget;
use Kinetis\VectorAi\Governance\ModelBoundary;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GovernedTool around a tool of the test's own: what reaches the inner
 * tool (clamped arguments, nothing past the budget or for a resource kept
 * from the model, nor a query that filters, sorts or searches on a field
 * kept back), and what comes back (the boundary's cut after the
 * preamble; a Vector refusal as a tool error; anything else ending the run).
 */
final class GovernedToolTest extends AiTestCase
{
    private ?RequestScope $scope = null;

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);

        if ($this->name() === 'testAProjectionNamingNoPageSizeGetsNoMoreThanItsResourcesMaximum') {
            // products page at most 40 rows, fewer than a one-field projection's 200
            $registry = $app->get(VectorRegistry::class);
            self::assertInstanceOf(VectorRegistry::class, $registry);
            $data = $registry->toArray();
            $data['resources']['products']['table_view']['maxPerPage'] = 40;
            $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));

            return;
        }

        if ($this->name() !== 'testAnAggregateKeepsBackARelationsTitleAndAMoneyFieldsCurrencyKeptFromTheModel') {
            return;
        }

        // categories' name and orders' currency are kept from the model
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();

        foreach (['categories' => 'name', 'orders' => 'currency'] as $slug => $kept) {
            foreach ($data['resources'][$slug]['fields'] as $i => $field) {
                if ($field['name'] === $kept) {
                    $data['resources'][$slug]['fields'][$i]['meta']['ai.send'] = false;
                }
            }
        }

        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->scope?->dispose();
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'ai')->delete();
        parent::tearDown();
    }

    public function testAQueryIsClampedAndItsAnswerCutAfterThePreamble(): void
    {
        $inner = new RecordingTool('vector_query', ['data' => [['id' => 1, 'name' => 'Ada', 'passwordHash' => 'x', 'email' => 'ada@example.com']], 'perPage' => 25]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'users', 'perPage' => 500, 'page' => 99]);
        $tool->execute();

        self::assertSame(['resource' => 'users', 'perPage' => 25, 'page' => 20], $inner->received);
        self::assertSame(GovernedTool::PREAMBLE . "\n" . '{"data":[{"id":1,"name":"Ada"}],"perPage":25}', $tool->getResult());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function queriesOnAFieldKeptBack(): array
    {
        return [
            'sort' => [['sort' => 'email'], GovernedTool::FIELD_REFUSED],
            'sort descending' => [['sort' => '-email'], GovernedTool::FIELD_REFUSED],
            'sort, second' => [['sort' => 'name, -email'], GovernedTool::FIELD_REFUSED],
            'filter' => [['filter' => ['email' => ['contains' => '@gmail.com']]], GovernedTool::FIELD_REFUSED],
            'filter in or' => [['filter' => ['isAdmin' => 'true', 'or' => [['name' => 'Ada'], ['email' => 'ada@example.com']]]], GovernedTool::FIELD_REFUSED],
            'filter in and, in or' => [['filter' => ['and' => [['or' => [['email' => ['endsWith' => '.org']]]]]]], GovernedTool::FIELD_REFUSED],
            'password filter' => [['filter' => ['passwordHash' => 'x']], GovernedTool::FIELD_REFUSED],
            // users search their email too: which users a term matches would tell what it holds
            'search' => [['q' => '@gmail.com'], GovernedTool::SEARCH_REFUSED],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('queriesOnAFieldKeptBack')]
    public function testAQueryNamingOrSearchingAFieldKeptFromTheModelIsNotDispatched(array $arguments, string $refusal): void
    {
        $inner = new RecordingTool('vector_query', ['data' => []]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'users', ...$arguments]);
        $tool->execute();

        self::assertNull($inner->received, 'the query reached the dispatcher');
        self::assertEquals(ToolOutput::error($refusal), $tool->getResult());
        self::assertStringNotContainsString('email', $refusal, 'the refusal names the field kept back');
    }

    public function testAQueryOnFieldsTheModelMayBeSentIsDispatchedAsWritten(): void
    {
        $inner = new RecordingTool('vector_query', ['data' => []]);
        $tool = $this->governed($inner, new ToolBudget(8));
        $filter = ['isAdmin' => 'true', 'or' => [['name' => 'Ada'], ['id' => ['gt' => '3']]]];

        $tool->setInputs(['resource' => 'users', 'filter' => $filter, 'sort' => '-name,id']);
        $tool->execute();
        self::assertSame(['resource' => 'users', 'filter' => $filter, 'sort' => '-name,id', 'perPage' => 25], $inner->received);

        // products search their SKU, name and category's name: all may be sent
        $tool->setInputs(['resource' => 'products', 'q' => 'lamp']);
        $tool->execute();
        self::assertSame(['resource' => 'products', 'q' => 'lamp', 'perPage' => 25], $inner->received);
    }

    public function testUnderTheAllowListAQueryNamingNoSortIsOrderedByWhatMayBeSent(): void
    {
        $this->client->request('PUT', '/vector/settings/ai', body: ['dataDefault' => 'allowlist'], headers: $this->adminHeaders())->assertOk();
        $inner = new RecordingTool('vector_query', ['data' => []]);
        $tool = $this->governed($inner, new ToolBudget(8));

        // products order by -createdAt, which does not opt in: by their id instead
        $tool->setInputs(['resource' => 'products', 'filter' => ['stock' => ['lte' => '5']]]);
        $tool->execute();
        self::assertSame(['resource' => 'products', 'filter' => ['stock' => ['lte' => '5']], 'perPage' => 25, 'sort' => 'id'], $inner->received);

        // a sort of separators alone names no field: the list would fall back to its default sort
        foreach ([',', ' , ', ',,'] as $empty) {
            $inner->received = null;
            $tool->setInputs(['resource' => 'products', 'sort' => $empty]);
            $tool->execute();
            self::assertSame(['resource' => 'products', 'sort' => 'id', 'perPage' => 25], $inner->received, "sort \"{$empty}\"");
        }

        // their SKU does not opt in, and search matches it
        $inner->received = null;
        $tool->setInputs(['resource' => 'products', 'q' => 'SKU-1']);
        $tool->execute();
        self::assertNull($inner->received);
        self::assertEquals(ToolOutput::error(GovernedTool::SEARCH_REFUSED), $tool->getResult());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function aggregatesOnAFieldKeptBack(): array
    {
        return [
            'group' => [['groupBy' => [['field' => 'email']], 'metrics' => [['fn' => 'count']]], GovernedTool::FIELD_REFUSED],
            'second group' => [['groupBy' => [['field' => 'isAdmin'], ['field' => 'email']], 'metrics' => [['fn' => 'count']]], GovernedTool::FIELD_REFUSED],
            'metric' => [['metrics' => [['fn' => 'count'], ['fn' => 'min', 'field' => 'email']]], GovernedTool::FIELD_REFUSED],
            'filter' => [['metrics' => [['fn' => 'count']], 'filter' => ['or' => [['email' => ['like' => 'gmail']]]]], GovernedTool::FIELD_REFUSED],
            'sort' => [['groupBy' => [['field' => 'isAdmin']], 'metrics' => [['fn' => 'count']], 'sort' => '-email'], GovernedTool::FIELD_REFUSED],
            'sort by a metric of it' => [['metrics' => [['fn' => 'count']], 'sort' => '-max_email'], GovernedTool::FIELD_REFUSED],
            'password' => [['metrics' => [['fn' => 'max', 'field' => 'passwordHash']]], GovernedTool::FIELD_REFUSED],
            'search' => [['metrics' => [['fn' => 'count']], 'q' => '@gmail.com'], GovernedTool::SEARCH_REFUSED],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('aggregatesOnAFieldKeptBack')]
    public function testAnAggregateNamingOrSearchingAFieldKeptFromTheModelIsNotDispatched(array $arguments, string $refusal): void
    {
        $inner = new RecordingTool('vector_aggregate', ['groups' => []]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'users', ...$arguments]);
        $tool->execute();

        self::assertNull($inner->received, 'the aggregate reached the dispatcher');
        self::assertEquals(ToolOutput::error($refusal), $tool->getResult());
    }

    public function testAProjectionNamingAFieldKeptFromTheModelIsNotDispatched(): void
    {
        $inner = new RecordingTool('vector_query', ['data' => []]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'users', 'fields' => ['name', 'email']]);
        $tool->execute();

        self::assertNull($inner->received);
        self::assertEquals(ToolOutput::error(GovernedTool::FIELD_REFUSED), $tool->getResult());
    }

    /** @return array<string, array{array<string, mixed>, int}> */
    public static function pageSizes(): array
    {
        $fields = ['id', 'sku', 'name', 'slug', 'status', 'stock', 'isFeatured', 'releasedAt', 'createdAt', 'updatedAt'];

        return [
            'no fields, asked for more' => [['perPage' => 500], 25],
            'no fields, none asked' => [[], 25],
            'empty fields' => [['fields' => [], 'perPage' => 100], 25],
            'one field' => [['fields' => ['name'], 'perPage' => 500], 200],
            'five fields: 1000 / 5' => [['fields' => array_slice($fields, 0, 5), 'perPage' => 500], 200],
            'six fields: 1000 / 6' => [['fields' => array_slice($fields, 0, 6), 'perPage' => 500], 166],
            'seven fields: 1000 / 7' => [['fields' => array_slice($fields, 0, 7), 'perPage' => 500], 142],
            'fields, fewer asked' => [['fields' => ['name'], 'perPage' => 30], 30],
            'ten fields, none asked' => [['fields' => $fields], 100],
        ];
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('pageSizes')]
    public function testAQuerysPageIsClampedByHowManyFieldsItProjects(array $arguments, int $perPage): void
    {
        $inner = new RecordingTool('vector_query', ['data' => array_fill(0, 250, ['id' => 1, 'name' => 'Ada'])]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'products', ...$arguments]);
        $tool->execute();

        self::assertNotNull($inner->received, (string) json_encode($tool->getResult()));
        self::assertSame($perPage, $inner->received['perPage']);

        // the boundary sends no more rows than the rule lets through, whatever the tool answered
        $sent = json_decode(substr((string) $tool->getResult(), strlen(GovernedTool::PREAMBLE) + 1), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($sent);
        $most = ($arguments['fields'] ?? []) === [] ? 25 : min(200, intdiv(1000, count($arguments['fields'])));
        self::assertSame($most, ModelBoundary::maxRows($arguments['fields'] ?? null));
        self::assertCount($most, $sent['data']);
        self::assertSame(250 - $most, $sent['omittedRows']);
    }

    public function testAProjectionNamingNoPageSizeGetsNoMoreThanItsResourcesMaximum(): void
    {
        $inner = new RecordingTool('vector_query', ['data' => [['id' => 1, 'name' => 'Ada']]]);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'products', 'fields' => ['name']]);
        $tool->execute();

        // the size rule would allow 200; products allow 40, and the list would refuse more
        self::assertSame(['resource' => 'products', 'fields' => ['name'], 'perPage' => 40], $inner->received);
    }

    public function testAnAggregatesAnswerIsCutAfterThePreamble(): void
    {
        $answer = [
            'groups' => [['key' => ['category' => ['id' => 3, 'label' => 'Lamps'], 'status' => 'active'], 'metrics' => ['sum_stock' => 42, 'count' => 2]]],
            'groupCount' => 1,
            'truncated' => false,
            'timezone' => 'UTC',
            'unexpected' => 'dropped',
        ];
        $inner = new RecordingTool('vector_aggregate', $answer);
        $tool = $this->governed($inner, new ToolBudget(8));
        $arguments = ['resource' => 'products', 'groupBy' => [['field' => 'category'], ['field' => 'status']], 'metrics' => [['fn' => 'sum', 'field' => 'stock'], ['fn' => 'count']]];

        $tool->setInputs($arguments);
        $tool->execute();

        self::assertSame($arguments, $inner->received, 'an aggregate is dispatched as written');
        self::assertSame(
            GovernedTool::PREAMBLE . "\n" . '{"groups":[{"key":{"category":{"id":3,"label":"Lamps"},"status":"active"},"metrics":{"sum_stock":42,"count":2}}],"groupCount":1,"truncated":false,"timezone":"UTC"}',
            $tool->getResult(),
        );

        // an answer naming a field kept back is not sent, whatever asked for it
        $leaking = $this->governed(new RecordingTool('vector_aggregate', ['groups' => [['key' => ['email' => 'ada@example.com'], 'metrics' => ['count' => 1]]]]), new ToolBudget(8));
        $leaking->setInputs(['resource' => 'users', 'metrics' => [['fn' => 'count']]]);
        $leaking->execute();
        self::assertSame(GovernedTool::PREAMBLE . "\n" . '{"error":"' . ModelBoundary::UNAVAILABLE . '"}', $leaking->getResult());
    }

    public function testAnAggregateKeepsBackARelationsTitleAndAMoneyFieldsCurrencyKeptFromTheModel(): void
    {
        $inner = new RecordingTool('vector_aggregate', ['groups' => [['key' => ['category' => ['id' => 3, 'label' => 'Lamps']], 'metrics' => ['count' => 2]]], 'groupCount' => 1, 'truncated' => false, 'timezone' => 'UTC']);
        $tool = $this->governed($inner, new ToolBudget(8));

        $tool->setInputs(['resource' => 'products', 'groupBy' => [['field' => 'category']], 'metrics' => [['fn' => 'count']]]);
        $tool->execute();
        self::assertSame(GovernedTool::PREAMBLE . "\n" . '{"groups":[{"key":{"category":{"id":3}},"metrics":{"count":2}}],"groupCount":1,"truncated":false,"timezone":"UTC"}', $tool->getResult());

        // a sum of order totals would group by their currency, which is kept back
        $money = new RecordingTool('vector_aggregate', ['groups' => []]);
        $orders = $this->governed($money, new ToolBudget(8));
        $orders->setInputs(['resource' => 'orders', 'metrics' => [['fn' => 'sum', 'field' => 'totalCents']]]);
        $orders->execute();
        self::assertNull($money->received);
        self::assertEquals(ToolOutput::error(GovernedTool::FIELD_REFUSED), $orders->getResult());

        // counted, nothing of the currency is read
        $orders->setInputs(['resource' => 'orders', 'groupBy' => [['field' => 'status']], 'metrics' => [['fn' => 'count']]]);
        $orders->execute();
        self::assertNotNull($money->received);
    }

    public function testAnAggregateIsOneOfTheAssistantsToolsAndSpendsTheBudget(): void
    {
        self::assertContains('vector_aggregate', Assistant::TOOLS);

        $budget = new ToolBudget(1);
        $inner = new RecordingTool('vector_aggregate', ['groups' => []]);
        $tool = $this->governed($inner, $budget);

        $tool->setInputs(['resource' => 'products', 'metrics' => [['fn' => 'count']]]);
        $tool->execute();
        self::assertNotNull($inner->received);

        $inner->received = null;
        $tool->setInputs(['resource' => 'products', 'metrics' => [['fn' => 'count']]]);
        $tool->execute();
        self::assertNull($inner->received);
        self::assertEquals(ToolOutput::error(GovernedTool::EXHAUSTED), $tool->getResult());
        self::assertSame([['name' => 'vector_aggregate', 'ok' => true]], $budget->calls());
    }

    public function testNothingIsDispatchedForAResourceKeptFromTheModelOrPastTheBudget(): void
    {
        $budget = new ToolBudget(1);
        $inner = new RecordingTool('vector_get', ['data' => ['id' => 1]]);
        $tool = $this->governed($inner, $budget);

        $tool->setInputs(['resource' => 'profiles', 'id' => '1']);
        $tool->execute();
        self::assertNull($inner->received);
        self::assertEquals(ToolOutput::error(ModelBoundary::UNAVAILABLE), $tool->getResult());

        $tool->setInputs(['resource' => 'users', 'id' => '1']);
        $tool->execute();
        self::assertNull($inner->received, 'the refused call spent the budget of one');
        self::assertEquals(ToolOutput::error(GovernedTool::EXHAUSTED), $tool->getResult());
    }

    public function testAModelThatKeepsCallingPastTheBudgetEndsTheRun(): void
    {
        $budget = new ToolBudget(0);
        $tool = $this->governed(new RecordingTool('vector_resources', []), $budget);

        $this->expectException(RunawayToolCallsException::class);

        for ($i = 0; $i <= ToolBudget::RUNAWAY; ++$i) {
            $tool->setInputs([]);
            $tool->execute();
        }
    }

    public function testAVectorRefusalIsAToolErrorAndAnythingElseEndsTheRun(): void
    {
        $refusing = $this->governed(new RecordingTool('vector_query', new InvalidListRequestException('"x" is not a filterable field of users.')), new ToolBudget(8));
        $refusing->setInputs(['resource' => 'users']);
        $refusing->execute();
        self::assertEquals(ToolOutput::error('"x" is not a filterable field of users.'), $refusing->getResult());

        $broken = $this->governed(new RecordingTool('vector_query', new \LogicException('A bug.')), new ToolBudget(8));
        $broken->setInputs(['resource' => 'users']);
        $this->expectException(\LogicException::class);
        $broken->execute();
    }

    public function testACloneSharesTheBudgetButNotTheInnerToolsCall(): void
    {
        $budget = new ToolBudget(2);
        $tool = $this->governed(new RecordingTool('vector_resources', ['resources' => []]), $budget);

        foreach ([clone $tool, clone $tool] as $call) {
            $call->setInputs([]);
            $call->execute();
        }

        self::assertSame(2, $budget->made());
        self::assertSame(['vector_resources', 'vector_resources'], $budget->names());
    }

    public function testTheBudgetRecordsWhetherEachCallAnsweredOrWasAToolError(): void
    {
        $budget = new ToolBudget(3);
        $answering = $this->governed(new RecordingTool('vector_query', ['data' => []]), $budget);
        $refusing = $this->governed(new RecordingTool('vector_query', new InvalidListRequestException('"x" is not a sortable field of users.')), $budget);

        $answering->setInputs(['resource' => 'users']);
        $answering->execute();
        $refusing->setInputs(['resource' => 'users', 'sort' => 'x']);
        $refusing->execute();
        $answering->setInputs(['resource' => 'profiles']);
        $answering->execute();
        // past the budget: refused before it ran, and not one of the calls made
        $answering->setInputs(['resource' => 'users']);
        $answering->execute();

        self::assertSame([
            ['name' => 'vector_query', 'ok' => true],
            ['name' => 'vector_query', 'ok' => false],
            ['name' => 'vector_query', 'ok' => false],
        ], $budget->calls());
        self::assertSame(['vector_query', 'vector_query', 'vector_query'], $budget->names());
    }

    private function governed(RecordingTool $inner, ToolBudget $budget): GovernedTool
    {
        $this->scope ??= $this->app->createRequestScope();
        $this->scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Admin', true));
        $boundary = $this->scope->get(ModelBoundary::class);
        self::assertInstanceOf(ModelBoundary::class, $boundary);

        return new GovernedTool($inner, $boundary, $budget);
    }
}

/** A tool that records the arguments it ran with, and answers $answer as JSON — or throws it. */
final class RecordingTool extends Tool
{
    /** @var array<array-key, mixed>|null */
    public ?array $received = null;

    /** @param array<array-key, mixed>|\Throwable $answer */
    public function __construct(string $name, private readonly array|\Throwable $answer)
    {
        $this->name = $name;
    }

    #[\Override]
    public function execute(): void
    {
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        $this->received = $this->inputs;
        $this->setResult(json_encode($this->answer, JSON_THROW_ON_ERROR));
    }
}
