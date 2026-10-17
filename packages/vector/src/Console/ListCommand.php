<?php

declare(strict_types=1);

namespace Kinetis\Vector\Console;

use Kinetis\Cache\DiscoveryContext;
use Kinetis\Console\Attributes\Command;
use Kinetis\Runtime\ProjectRoot;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\SettingsDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * A read-only introspection tool, as routes:list is for routes: displays
 * the resources, settings groups and dashboards Vector compiles, via a
 * fresh, live scan through its own DiscoveryContext — never reads or
 * writes .kinetis-cache/. A compile error propagates with the registry's
 * own message, as it would from `kinetis build`.
 *
 * This is the compiled registry: labels as declared, before translation,
 * and every resource whatever its policy. What a given user sees is
 * `GET /vector/schema` or the MCP tool `vector_describe`.
 *
 * $output is injectable (defaulting to STDOUT) for testability against
 * php://memory — a #[Command] method must take zero parameters or
 * exactly one CommandArguments, so it can't be a run() parameter.
 */
final readonly class ListCommand
{
    /**
     * @param resource $output mixed, not resource, since PHP has no native
     *     "resource" type and a readonly property requires one
     */
    public function __construct(
        private ProjectRoot $projectRoot,
        private mixed $output = STDOUT,
    ) {}

    #[Command('vector:list', description: 'Displays the resources, settings groups and dashboards Vector compiles', bootstrap: false)]
    public function run(): int
    {
        $registry = VectorRegistry::fromArray(VectorRegistry::compile(new DiscoveryContext($this->projectRoot->path)));

        $this->printResources($registry->resources());
        $this->write("\n");
        $this->printSettings($registry->settings());
        $this->write("\n");
        $this->printDashboards($registry->dashboards());

        return 0;
    }

    /**
     * @param array<string, ResourceDescriptor> $resources
     */
    private function printResources(array $resources): void
    {
        $this->write("Resources:\n");

        if ($resources === []) {
            $this->write("No resources discovered.\n");

            return;
        }

        ksort($resources, SORT_STRING);

        $this->printTable(
            ['Slug', 'Label', 'Group', 'Entity', 'Resource', 'Connection'],
            array_values(array_map(
                static fn (ResourceDescriptor $resource): array => [
                    [$resource->slug()],
                    [self::text($resource->label())],
                    [self::text($resource->toArray()['group'] ?? null)],
                    [$resource->entityClass()],
                    [$resource->resourceClass()],
                    [$resource->connection()],
                ],
                $resources,
            )),
        );
    }

    /**
     * @param array<string, SettingsDescriptor> $groups
     */
    private function printSettings(array $groups): void
    {
        $this->write("Settings groups:\n");

        if ($groups === []) {
            $this->write("No settings groups discovered.\n");

            return;
        }

        ksort($groups, SORT_STRING);

        $this->printTable(
            ['Name', 'Label', 'Class'],
            array_values(array_map(
                static fn (SettingsDescriptor $group): array => [
                    [$group->name()],
                    [self::text($group->label())],
                    [$group->groupClass()],
                ],
                $groups,
            )),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $dashboards
     */
    private function printDashboards(array $dashboards): void
    {
        $this->write("Dashboards:\n");

        if ($dashboards === []) {
            $this->write("No dashboards discovered.\n");

            return;
        }

        ksort($dashboards, SORT_STRING);
        $rows = [];

        foreach ($dashboards as $name => $dashboard) {
            $rows[] = [
                [$name],
                [self::text($dashboard['label'] ?? null)],
                [self::text($dashboard['class'] ?? null)],
            ];
        }

        $this->printTable(['Name', 'Label', 'Class'], $rows);
    }

    /** A compiled string, or `—` for an empty or absent one. */
    private static function text(mixed $value): string
    {
        return is_string($value) && $value !== '' ? $value : '—';
    }

    /**
     * @param list<string> $headers
     * @param list<non-empty-list<list<string>>> $rows each cell is its own list of lines
     */
    private function printTable(array $headers, array $rows): void
    {
        $widths = array_map(self::width(...), $headers);

        foreach ($rows as $row) {
            foreach ($row as $column => $lines) {
                foreach ($lines as $line) {
                    $widths[$column] = max($widths[$column], self::width($line));
                }
            }
        }

        $this->printRow($headers, $widths);
        $this->write(implode('  ', array_map(static fn (int $width): string => str_repeat('-', $width), $widths)) . "\n");

        foreach ($rows as $row) {
            $this->printTableRow($row, $widths);
        }
    }

    /**
     * One entry spans as many lines as its tallest cell; a shorter cell
     * is left blank below its own last line.
     *
     * @param non-empty-list<list<string>> $row
     * @param array<int, int> $widths
     */
    private function printTableRow(array $row, array $widths): void
    {
        $height = max(array_map(count(...), $row));

        for ($line = 0; $line < $height; $line++) {
            $this->printRow(
                array_map(static fn (array $lines): string => $lines[$line] ?? '', $row),
                $widths,
            );
        }
    }

    /**
     * Pads by self::width(), so the multibyte `—` placeholder keeps every
     * later column aligned.
     *
     * @param list<string> $columns
     * @param array<int, int> $widths
     */
    private function printRow(array $columns, array $widths): void
    {
        $padded = [];

        foreach ($columns as $index => $value) {
            $padded[] = $value . str_repeat(' ', $widths[$index] - self::width($value));
        }

        $this->write(rtrim(implode('  ', $padded)) . "\n");
    }

    /**
     * Counts UTF-8 characters with PCRE rather than mbstring, which the
     * framework does not require. Text that is not valid UTF-8 counts
     * bytes.
     */
    private static function width(string $text): int
    {
        return preg_match_all('/./su', $text) ?: strlen($text);
    }

    private function write(string $line): void
    {
        fwrite($this->output, $line);
    }
}
