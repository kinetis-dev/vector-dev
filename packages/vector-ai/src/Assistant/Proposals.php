<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

use Kinetis\VectorAi\Approvals\Approval;

/**
 * The changes one assistant run proposed, in order: every ProposingTool
 * of the run adds the approval it stored, and the run hands each to the
 * admin once, as a stream frame (drain()) or in the whole answer
 * (all()). One per run, shared by the run's tools and their clones;
 * never shared between runs.
 */
final class Proposals
{
    /** @var list<Approval> */
    private array $approvals = [];

    /** How many approvals drain() has handed out. */
    private int $drained = 0;

    public function add(Approval $approval): void
    {
        $this->approvals[] = $approval;
    }

    /** @return list<Approval> every approval of the run */
    public function all(): array
    {
        return $this->approvals;
    }

    /** @return list<Approval> the approvals added since the last drain() */
    public function drain(): array
    {
        $new = array_slice($this->approvals, $this->drained);
        $this->drained = count($this->approvals);

        return $new;
    }
}
