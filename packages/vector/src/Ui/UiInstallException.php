<?php

declare(strict_types=1);

namespace Kinetis\Vector\Ui;

/**
 * A step of installing the hosted admin UI failed for a reason outside
 * the bundle itself: the registry could not be reached or answered
 * badly, or a file or directory could not be opened, created or
 * written. A refused archive or bundle is an \UnexpectedValueException
 * instead.
 *
 * A \RuntimeException, so every caller that catches one still does.
 */
final class UiInstallException extends \RuntimeException {}
