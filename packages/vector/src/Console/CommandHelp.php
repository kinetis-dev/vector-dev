<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Console\Attributes\Command;
use Kinetis\Console\CommandArguments;
use Kinetis\Console\Output;

/**
 * `--help` and `-h` for Vector's commands. kinetis/framework has no help
 * of its own: `kinetis <name> --help` hands `--help` to the command like
 * any other argument, so a command that does not look for it runs. Each
 * Vector command asks requested() first and, when it is true, prints()
 * and returns before it reads, writes or deletes anything.
 *
 * The name and the description come from the method's own #[Command],
 * so the help says what `kinetis` lists.
 */
final readonly class CommandHelp
{
    /** Whether the arguments ask for help: `--help` anywhere, or `-h`. */
    public static function requested(CommandArguments $arguments): bool
    {
        return $arguments->hasOption('help') || in_array('-h', $arguments->all(), true);
    }

    /**
     * Prints the help of the command $class::$method declares, to stdout.
     *
     * @param class-string $class
     * @param list<string> $forms each way to call it, the arguments after the command's name
     * @return int the exit code, 0
     */
    public static function print(Output $output, string $class, string $method, array $forms = ['']): int
    {
        $attributes = new \ReflectionClass($class)->getMethod($method)->getAttributes(Command::class);

        if ($attributes === []) {
            throw new \LogicException("{$class}::{$method} declares no #[Command].");
        }

        $command = $attributes[0]->newInstance();

        $output->line('Usage:');

        foreach ($forms as $form) {
            $output->line(rtrim("  kinetis {$command->name} {$form}"));
        }

        $output->line();

        if ($command->description !== '') {
            $output->line($command->description);
            $output->line();
        }

        $output->line('-h, --help prints this help and runs nothing.');

        return 0;
    }
}
