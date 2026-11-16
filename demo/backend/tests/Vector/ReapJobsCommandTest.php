<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * `vector:jobs:reap`, as `kinetis` runs it: what it did on stdout,
 * nothing on stderr. The first run reaps whatever the demo has stalled;
 * the second finds nothing left and says so.
 */
final class ReapJobsCommandTest extends VectorTestCase
{
    public function testItReportsOnStdoutAndASecondRunFindsNothing(): void
    {
        $first = $this->application->command('vector:jobs:reap');

        self::assertNull($first->exception);
        self::assertSame(0, $first->exitCode);
        self::assertSame('', $first->errorOutput);
        self::assertMatchesRegularExpression('/\A(?:\d+ stalled job\(s\) marked failed\.|No stalled jobs \(running rows silent for \d+ minutes or more\)\.)\n\z/', $first->output);

        $second = $this->application->command('vector:jobs:reap');

        self::assertSame(0, $second->exitCode);
        self::assertSame('', $second->errorOutput);
        self::assertMatchesRegularExpression('/\ANo stalled jobs \(running rows silent for \d+ minutes or more\)\.\n\z/', $second->output);
    }
}
