<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every Vector and kinetis/vector-ai command, run as `kinetis` runs it
 * through TestApplication::command(), answers `--help` and `-h` with its
 * usage on stdout, exit 0, and runs nothing: kinetis/framework hands the
 * flag to the command, so a command that ignored it would publish, prune
 * or reap instead. Each command's own test shows that for the files and
 * rows it would touch; this one covers the dispatch of all of them.
 */
final class CommandHelpTest extends VectorTestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function commands(): iterable
    {
        foreach ([
            'vector:install',
            'vector:install-ui',
            'vector:list',
            'vector:files:prune',
            'vector:jobs:reap',
            'vector:queues:prune-metrics',
            'vector-ai:install',
            'vector-ai:approvals:prune',
        ] as $command) {
            foreach (['--help', '-h'] as $flag) {
                yield "{$command} {$flag}" => [$command, $flag];
            }
        }
    }

    #[DataProvider('commands')]
    public function testHelpPrintsUsageAndRunsNothing(string $command, string $flag): void
    {
        $result = $this->application->command($command, [$flag]);

        self::assertNull($result->exception);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->errorOutput);
        self::assertStringStartsWith("Usage:\n  kinetis {$command}", $result->output);
        self::assertStringEndsWith("\n-h, --help prints this help and runs nothing.\n", $result->output);
    }
}
