<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Assistant;

/** A model kept calling tools after its run's budget was spent: the run ends. */
final class RunawayToolCallsException extends \RuntimeException {}
