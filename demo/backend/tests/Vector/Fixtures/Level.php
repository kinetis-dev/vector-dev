<?php

declare(strict_types=1);

namespace App\Tests\Vector\Fixtures;

/** An int-backed enum: the demo's own enums are all string-backed. */
enum Level: int
{
    case Low = 1;
    case High = 2;
}
