<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Console\Attributes\Command;
use Kinetis\Vector\Console\InstallCommand;
use Kinetis\Vector\Console\InstallUiCommand;
use Kinetis\Vector\Console\ListCommand;
use Kinetis\Vector\Console\PruneFilesCommand;
use Kinetis\Vector\Console\ReapJobsCommand;
use Kinetis\Vector\Queues\Console\PruneMetricsCommand;
use Kinetis\VectorAi\Console\InstallCommand as AiInstallCommand;
use Kinetis\VectorAi\Console\PruneApprovalsCommand;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every Vector and kinetis/vector-ai command answers `--help` and `-h`
 * with the help kinetis/framework builds from its discovered #[Command]:
 * the usage line and the description on stdout, exit 0. The framework
 * answers before it resolves the command, so the command runs nothing:
 * no migration is published, no UI installed, no upload deleted and no
 * row reaped or pruned. TestApplication::command() answers the flag the
 * same way bin/kinetis does.
 */
final class ConsoleHelpTest extends VectorTestCase
{
    /** @return iterable<string, array{string, class-string, string, list<string>}> */
    public static function requests(): iterable
    {
        foreach ([
            [InstallCommand::class, 'install'],
            [InstallUiCommand::class, 'installUi'],
            [ListCommand::class, 'run'],
            [PruneFilesCommand::class, 'prune'],
            [ReapJobsCommand::class, 'reap'],
            [PruneMetricsCommand::class, 'run'],
            [AiInstallCommand::class, 'install'],
            [PruneApprovalsCommand::class, 'run'],
        ] as [$class, $method]) {
            $name = self::attribute($class, $method)->name;

            yield "{$name} --help" => [$name, $class, $method, ['--help']];
            yield "{$name} -h" => [$name, $class, $method, ['-h']];
        }

        yield 'vector:install --help beside an option' => ['vector:install', InstallCommand::class, 'install', ['--connection=admin', '--help']];
    }

    /**
     * @param class-string $class
     * @param list<string> $arguments
     */
    #[DataProvider('requests')]
    public function testHelpPrintsTheDiscoveredUsageAndRunsNothing(string $command, string $class, string $method, array $arguments): void
    {
        $takesArguments = new \ReflectionMethod($class, $method)->getNumberOfParameters() > 0;
        $usage = "Usage:\n  kinetis {$command}" . ($takesArguments ? ' [arguments]' : '') . "\n";

        $result = $this->application->command($command, $arguments);

        self::assertNull($result->exception);
        self::assertSame(0, $result->exitCode);
        self::assertSame('', $result->errorOutput);
        self::assertSame("{$usage}\n" . self::attribute($class, $method)->description . "\n", $result->output);
    }

    /** @param class-string $class */
    private static function attribute(string $class, string $method): Command
    {
        $attributes = new \ReflectionMethod($class, $method)->getAttributes(Command::class);
        self::assertCount(1, $attributes);

        return $attributes[0]->newInstance();
    }
}
