<?php

declare(strict_types=1);

namespace Kinetis\Vector\Import;

use Kinetis\Vector\Exception\InvalidListRequestException;
use Kinetis\Vector\Query\CsvWriter;

/**
 * A CSV document in memory: a header row and data rows as maps keyed by
 * header. Accepts a UTF-8 byte-order mark, `,` or `;` as the delimiter
 * (detected on the header), and refuses more than MAX_ROWS rows.
 */
final class CsvReader
{
    public const int MAX_ROWS = 50000;

    /** What a failed-rows file keeps free, past its rows, for the note on the rows it left out. */
    private const int NOTE_ROOM = 1024;

    /** @var list<string> */
    private array $headers;

    /** @var list<list<string>> */
    private array $data;

    private function __construct(string $contents)
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $stream = fopen('php://memory', 'r+');

        if ($stream === false) {
            throw new CsvReadException('Could not open a memory stream.');
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
     * @param int|null $maxBytes the most the document may hold; past it only the rows that fit are kept, whole, and the last of them says in its last cell how many were left out
     */
    public static function toCsv(array $headers, array $rows, ?int $maxBytes = null): string
    {
        $out = fopen('php://memory', 'r+');

        if ($out === false) {
            throw new CsvReadException('Could not open a memory stream.');
        }

        fwrite($out, "\xEF\xBB\xBF");
        // the cells came from an upload: the export's guard keeps a spreadsheet from evaluating them
        fputcsv($out, array_map(CsvWriter::guarded(...), $headers), ',', '"', '\\', "\r\n");

        $start = (int) ftell($out);
        $ends = [];

        foreach ($rows as $i => $row) {
            fputcsv($out, self::guardedRow($headers, $row), ',', '"', '\\', "\r\n");
            $ends[$i] = (int) ftell($out);

            if ($maxBytes !== null && $ends[$i] > $maxBytes) {
                // back to the last row that leaves room for the note, written again with it
                $kept = $i - 1;

                while ($kept >= 0 && $ends[$kept] > $maxBytes - self::NOTE_ROOM) {
                    $kept--;
                }

                ftruncate($out, max(0, $kept > 0 ? $ends[$kept - 1] : $start));
                fseek($out, 0, SEEK_END);
                $note = sprintf('%d more failed rows are not in this file, which holds at most %d MiB.', count($rows) - $kept - 1, intdiv($maxBytes, 1048576));
                $last = $kept >= 0 ? self::guardedRow($headers, $rows[$kept]) : array_fill(0, count($headers), '');
                $last[count($last) - 1] = $last[count($last) - 1] === '' ? $note : $last[count($last) - 1] . ' — ' . $note;
                fputcsv($out, $last, ',', '"', '\\', "\r\n");

                break;
            }
        }

        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv === false ? '' : $csv;
    }

    /**
     * @param list<string> $headers
     * @param array<string, string> $row
     * @return list<string>
     */
    private static function guardedRow(array $headers, array $row): array
    {
        return array_map(static fn (string $h): string => CsvWriter::guarded($row[$h] ?? ''), $headers);
    }
}
