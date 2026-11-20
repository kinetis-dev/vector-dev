<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

/**
 * How many tool calls one assistant run may make (`maxToolCallsPerRun`),
 * and the names of those it made, for the run's log line. One per run,
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

    /** @var list<string> */
    private array $names = [];

    public function __construct(private readonly int $limit) {}

    /**
     * Whether $tool may run now; a refused call is counted too.
     *
     * @throws RunawayToolCallsException when the model keeps calling past the budget
     */
    public function take(string $tool): bool
    {
        if ($this->made < $this->limit) {
            ++$this->made;
            $this->names[] = $tool;

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

    /** @return list<string> the tools that ran, in order */
    public function names(): array
    {
        return $this->names;
    }
}
