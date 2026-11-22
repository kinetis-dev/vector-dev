<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

/**
 * How many tool calls one assistant run may make (`maxToolCallsPerRun`),
 * and those it made, their names for the run's log line, each with
 * whether it answered or was a tool error for the whole answer's
 * `toolCalls` (settle(), asked by the tool once it has its result). One per run,
 * shared by every GovernedTool of it: Neuron runs a clone of a tool for
 * each call, and a clone shares this object. A model that keeps calling
 * after the budget is spent gets RUNAWAY more refusals, then the run ends.
 */
final class ToolBudget
{
    /** Refused calls past the budget after which the run ends. */
    public const int RUNAWAY = 3;

    private int $made = 0;

    private int $refused = 0;

    /** @var list<array{name: string, ok: bool}> */
    private array $calls = [];

    public function __construct(private readonly int $limit) {}

    /**
     * Whether $tool may run now; a refused call is counted too. A call that
     * may is the made()th, numbered from 0, until settle() says it answered.
     *
     * @throws RunawayToolCallsException when the model keeps calling past the budget
     */
    public function take(string $tool): bool
    {
        if ($this->made < $this->limit) {
            ++$this->made;
            $this->calls[] = ['name' => $tool, 'ok' => false];

            return true;
        }

        if (++$this->refused > self::RUNAWAY) {
            throw new RunawayToolCallsException("The assistant kept calling tools past its budget of {$this->limit}.");
        }

        return false;
    }

    /** How many tool calls ran. */
    public function made(): int
    {
        return $this->made;
    }

    /** Records whether call $call, numbered as take() numbered it, answered, rather than being a tool error. */
    public function settle(int $call, bool $ok): void
    {
        if (isset($this->calls[$call])) {
            $this->calls[$call]['ok'] = $ok;
        }
    }

    /** @return list<string> the tools that ran, in order */
    public function names(): array
    {
        return array_column($this->calls, 'name');
    }

    /** @return list<array{name: string, ok: bool}> the tools that ran, in order, each with whether it answered */
    public function calls(): array
    {
        return $this->calls;
    }
}
