<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Filters;

/**
 * A model's filter the list would not take, with the reason: the list
 * validator's own message, or what is wrong with the answer's shape. The
 * model is shown the reason once, in its repair turn; the second time,
 * the admin is (422).
 */
final class UnusableAnswerException extends \RuntimeException {}
