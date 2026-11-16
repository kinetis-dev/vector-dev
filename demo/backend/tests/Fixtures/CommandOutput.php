<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use Kinetis\Console\Output;

/**
 * An Output over two in-memory streams, colour off, for a command a test
 * constructs itself rather than running through
 * TestApplication::command(). stdout() and stderr() read everything
 * written so far.
 */
final class CommandOutput
{
    public readonly Output $output;

    /** @var resource */
    private mixed $stdout;

    /** @var resource */
    private mixed $stderr;

    public function __construct()
    {
        $this->stdout = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream.');
        $this->stderr = fopen('php://memory', 'w+') ?: throw new \RuntimeException('No memory stream.');
        $this->output = new Output($this->stdout, $this->stderr, false, false);
    }

    public function stdout(): string
    {
        return self::read($this->stdout);
    }

    public function stderr(): string
    {
        return self::read($this->stderr);
    }

    /**
     * @param resource $stream
     */
    private static function read(mixed $stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
