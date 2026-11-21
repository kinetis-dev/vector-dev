<?php

declare(strict_types=1);

namespace App\Tests\Vector\Ai;

use App\Auth\DemoActor;
use Kinetis\Container\RequestScope;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Vector\Exception\InvalidListRequestException;
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
