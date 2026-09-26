<?php

declare(strict_types=1);

namespace Kinetis\Vector\Exception;

use Kinetis\Http\Exception\HttpStatusExceptionInterface;
use RuntimeException;

/**
 * A request-time refusal the framework answers as `{"error": message}`
 * at the status each subclass declares.
 */
abstract class VectorException extends RuntimeException implements HttpStatusExceptionInterface {}
