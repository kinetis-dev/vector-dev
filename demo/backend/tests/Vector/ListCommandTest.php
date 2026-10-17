<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Console\ListCommand;
use PHPUnit\Framework\TestCase;

/**
 * vector:list against the demo, and against a temporary project of one
 * resource in no navigation group, which shares the demo's vendor/. No
 * database: the command compiles the registry from attributes alone.
 */
final class ListCommandTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            unlink("{$this->root}/vendor");
            unlink("{$this->root}/fixture");
            unlink("{$this->root}/composer.json");
            rmdir($this->root);
        }
    }

    public function testTheDemosResourcesAreListedSortedAndAligned(): void
    {
        [$status, $output] = self::list(dirname(__DIR__, 2));

        self::assertSame(0, $status);
        self::assertStringStartsWith("Resources:\n", $output);
        self::assertStringContainsString("\n\nSettings groups:\n", $output);
        self::assertStringContainsString("\n\nDashboards:\n", $output);

        $lines = explode("\n", explode("\n\n", $output)[0]);
        self::assertSame(['Slug', 'Label', 'Group', 'Entity', 'Resource', 'Connection'], preg_split('/ {2,}/', $lines[1]));

        // each column starts where its dash run does, on every row
        preg_match_all('/-+/', $lines[2], $runs, PREG_OFFSET_CAPTURE);
        $starts = array_column($runs[0], 1);
        $rows = array_slice($lines, 3);

        foreach ([$lines[1], ...$rows] as $line) {
            self::assertSame($line, rtrim($line));

            foreach (array_slice($starts, 1) as $start) {
                self::assertSame('  ', substr($line, $start - 2, 2), $line);
                self::assertNotSame(' ', $line[$start] ?? ' ', $line);
            }
        }

        $slugs = array_map(static fn (string $row): string => explode(' ', $row)[0], $rows);
        $sorted = $slugs;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $slugs);
        self::assertContains('products', $slugs);
        self::assertContains('tickets', $slugs);
        self::assertContains('users', $slugs);
        self::assertMatchesRegularExpression('/^tickets +Tickets +Support +App\\\\Domain\\\\Support\\\\Ticket +App\\\\Vector\\\\Support\\\\TicketResource +support$/m', $output);
        self::assertMatchesRegularExpression('/^store +Store +App\\\\Vector\\\\Settings\\\\StoreSettings$/m', $output);
        self::assertMatchesRegularExpression('/^main +Overview +App\\\\Vector\\\\Dashboards\\\\MainDashboard$/m', $output);
    }

    public function testAResourceInNoGroupShowsAnEmptyCellAndAnEmptySectionSaysSo(): void
    {
        $this->root = sys_get_temp_dir() . '/vector-list-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        symlink(dirname(__DIR__, 2) . '/vendor', "{$this->root}/vendor");
        symlink(dirname(__DIR__) . '/Fixtures/ListCommand', "{$this->root}/fixture");
        file_put_contents("{$this->root}/composer.json", json_encode(['autoload' => ['psr-4' => ['App\\Tests\\Fixtures\\ListCommand\\' => 'fixture/']]]));

        [$status, $output] = self::list($this->root);

        self::assertSame(0, $status);
        // the settings groups are Vector's own; the project declares no dashboard
        self::assertSame(<<<'TEXT'
            Resources:
            Slug   Label  Group  Entity                               Resource                                     Connection
            -----  -----  -----  -----------------------------------  -------------------------------------------  ----------
            notes  Notes  —      App\Tests\Fixtures\ListCommand\Note  App\Tests\Fixtures\ListCommand\NoteResource  default

            Settings groups:
            Name       Label            Class
            ---------  ---------------  ---------------------------------
            branding   Branding         Kinetis\Vector\Settings\Branding
            jobs       Background jobs  Kinetis\Vector\Settings\Jobs
            languages  Languages        Kinetis\Vector\Settings\Languages

            Dashboards:
            No dashboards discovered.

            TEXT, $output);
    }

    /** @return array{int, string} the exit status and everything written */
    private static function list(string $root): array
    {
        $output = fopen('php://memory', 'w+');
        self::assertIsResource($output);

        $status = new ListCommand(new ProjectRoot($root), $output)->run();
        rewind($output);

        return [$status, (string) stream_get_contents($output)];
    }
}
