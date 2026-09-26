<?php

declare(strict_types=1);

namespace Kinetis\Vector\Import;

use Kinetis\Vector\Exception\InvalidListRequestException;

/**
 * A CSV document in memory: a header row and data rows as maps keyed by
 * header. Accepts a UTF-8 byte-order mark, `,` or `;` as the delimiter
 * (detected on the header), and refuses more than MAX_ROWS rows.
 */
final class CsvReader
{
    public const int MAX_ROWS = 50000;

    /** @var list<string> */
    private array $headers;

    /** @var list<list<string>> */
    private array $data;

    private function __construct(string $contents)
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Could not open a memory stream.');
        }

        fwrite($stream, $contents);
        rewind($stream);
        $first = fgets($stream) ?: '';
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '\\');

        if (!is_array($header) || $header === [null]) {
            throw new InvalidListRequestException('The file has no header row.');
        }

        $this->headers = array_map(static fn (mixed $h): string => trim((string) $h), $header);
        $this->data = [];

        while (($row = fgetcsv($stream, null, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue; // a blank line
            }

            if (count($this->data) >= self::MAX_ROWS) {
                throw new InvalidListRequestException('The file has more than ' . self::MAX_ROWS . ' rows; split it.');
            }

            $this->data[] = array_map(static fn (mixed $v): string => (string) $v, $row);
        }

        fclose($stream);
    }

    public static function fromString(string $contents): self
    {
        return new self($contents);
    }

    /** @return list<string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function rowCount(): int
    {
        return count($this->data);
    }

    /** @return \Generator<int, array<string, string>> line number => header => value */
    public function rows(): \Generator
    {
        foreach ($this->data as $i => $row) {
            $record = [];

            foreach ($this->headers as $j => $header) {
                $record[$header] = $row[$j] ?? '';
            }

            yield $i + 2 => $record;
        }
    }

    /** @return list<array<string, string>> the first rows, for a preview */
    public function sample(int $limit = 5): array
    {
        $sample = [];

        foreach ($this->rows() as $row) {
            $sample[] = $row;

            if (count($sample) >= $limit) {
                break;
            }
        }

        return $sample;
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, string>> $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $out = fopen('php://memory', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a memory stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers, ',', '"', '\\', "\r\n");

        foreach ($rows as $row) {
            fputcsv($out, array_map(static fn (string $h): string => $row[$h] ?? '', $headers), ',', '"', '\\', "\r\n");
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }
}
