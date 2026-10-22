<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\RichText\Document;
use League\Flysystem\StorageAttributes;

/**
 * Deletes uploads no record uses. Only the folders of file and image
 * fields the registry declares are visited — `vector/{resource}/{field}/`,
 * one level deep — and only keys of the shape the upload endpoint issues;
 * nothing outside Vector's own `vector/` prefix is ever listed. A file
 * younger than the grace period is kept, as an upload a form has not
 * saved yet may be.
 *
 * A file is kept while anything can still point at it: a file or image
 * column of any row, trashed rows included; an image of a rich-text
 * document whose `imageField` is the field; or any value in the
 * resource's audit trail, since a revert writes an old value back.
 *
 * Tables and listings are read in chunks: the storage listing in batches
 * checked with one `IN` per file column, rows and audit entries by
 * primary key.
 */
final readonly class FilePruner
{
    public const int DEFAULT_GRACE_HOURS = 24;

    private const int BATCH = 500;

    private const string ISSUED = '~^[0-9a-f]{32}\.[a-z0-9]{1,10}$~D';

    public function __construct(
        private VectorRegistry $registry,
        private EntityManagerRegistry $managers,
        private VectorDatabase $db,
        private VectorStorage $storage,
        private Document $document,
    ) {}

    /**
     * @param \Closure(string): void $each told every key deleted, or that would be
     * @return int how many files were deleted, or would be
     */
    public function prune(int $graceSeconds, bool $dryRun, \Closure $each): int
    {
        $filesystem = $this->storage->filesystem();
        $cutoff = time() - $graceSeconds;
        $columns = $this->fileColumns();
        $count = 0;

        foreach ($this->registry->resources() as $resource) {
            foreach ($resource->fields() as $name => $field) {
                if (!in_array($field['type'], ['file', 'image'], true)) {
                    continue;
                }

                $prefix = "vector/{$resource->slug()}/{$name}/";
                $embedded = null; // read once a folder holds a candidate
                $batch = [];
                $flush = function () use (&$batch, &$count, $columns, $dryRun, $each, $filesystem): void {
                    foreach ($this->unreferenced($batch, $columns) as $key) {
                        if (!$dryRun) {
                            $filesystem->delete($key);
                        }

                        $each($key);
                        $count++;
                    }

                    $batch = [];
                };

                foreach ($filesystem->listContents(rtrim($prefix, '/'), false) as $item) {
                    $key = $item->path();

                    if (!$item->isFile() || !str_starts_with($key, $prefix) || preg_match(self::ISSUED, substr($key, strlen($prefix))) !== 1
                        || $this->modified($item) > $cutoff) {
                        continue;
                    }

                    $embedded ??= $this->embedded($resource, $name, $prefix);

                    if (!isset($embedded[$key])) {
                        $batch[] = $key;
                    }

                    if (count($batch) >= self::BATCH) {
                        $flush();
                    }
                }

                $flush();
            }
        }

        return $count;
    }

    private function modified(StorageAttributes $item): int
    {
        return $item->lastModified() ?? $this->storage->filesystem()->lastModified($item->path());
    }

    /**
     * The keys of $batch no file or image column holds, on any row.
     *
     * @param list<string> $batch
     * @param list<array{connection: string, table: string, column: string}> $columns
     * @return list<string>
     */
    private function unreferenced(array $batch, array $columns): array
    {
        $left = array_fill_keys($batch, true);

        foreach ($columns as $column) {
            if ($left === []) {
                break;
            }

            $held = $this->managers->manager($column['connection'])->builder()
                ->table($column['table'])->select($column['column'])->whereIn($column['column'], array_keys($left))->pluck($column['column']);

            foreach ($held as $key) {
                unset($left[(string) $key]);
            }
        }

        return array_map('strval', array_keys($left));
    }

    /**
     * Every file or image column of every resource, once each.
     *
     * @return list<array{connection: string, table: string, column: string}>
     */
    private function fileColumns(): array
    {
        $columns = [];

        foreach ($this->registry->resources() as $resource) {
            foreach ($resource->fields() as $name => $field) {
                $column = $resource->columns()[$name] ?? null;

                if ($column !== null && in_array($field['type'], ['file', 'image'], true)) {
                    $columns["{$resource->connection()}\0{$resource->table()}\0{$column}"] = ['connection' => $resource->connection(), 'table' => $resource->table(), 'column' => $column];
                }
            }
        }

        return array_values($columns);
    }

    /**
     * The keys under $prefix that rich-text documents using $fieldName
     * for their images show, on any row, and that the resource's audit
     * trail holds, as a set.
     *
     * @return array<string, true>
     */
    private function embedded(ResourceDescriptor $resource, string $fieldName, string $prefix): array
    {
        $keys = [];
        $collect = function (string $value) use (&$keys, $prefix): void {
            if (str_starts_with($value, $prefix)) {
                $keys[$value] = true;
            } elseif (str_contains($value, '"image"') || stripos($value, '<img') !== false) { // a document, its slashes escaped or not
                foreach ($this->document->imageKeys($value) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $keys[$key] = true;
                    }
                }
            }
        };

        foreach ($resource->fields() as $name => $field) {
            $column = $resource->columns()[$name] ?? null;

            if ($column === null || $field['type'] !== 'richtext' || ($field['richtext']['imageField'] ?? null) !== $fieldName) {
                continue;
            }

            $query = fn (): Query => $this->managers->manager($resource->connection())->builder()->table($resource->table())->where($column, '!=', null);

            foreach (self::chunks($query, $resource->primaryKeyColumn(), [$column]) as $row) {
                if (is_string($row[$column])) {
                    $collect($row[$column]);
                }
            }
        }

        $audit = fn (): Query => $this->db->query()->table('vector_audit')->where('resource', '=', $resource->slug());

        foreach (self::chunks($audit, 'id', ['changes']) as $row) {
            $changes = json_decode((string) $row['changes'], true);

            if (is_array($changes)) {
                array_walk_recursive($changes, static function (mixed $value) use ($collect): void {
                    if (is_string($value)) {
                        $collect($value);
                    }
                });
            }
        }

        return $keys;
    }

    /**
     * The rows of $query, read BATCH at a time in order of $key.
     *
     * @param \Closure(): Query $query a fresh builder on each call
     * @param list<string> $columns
     * @return \Generator<int, array<string, mixed>>
     */
    private static function chunks(\Closure $query, string $key, array $columns): \Generator
    {
        $after = null;

        do {
            $page = $query()->select($key, ...$columns)->orderBy($key, 'ASC')->limit(self::BATCH);

            if ($after !== null) {
                $page->where($key, '>', $after);
            }

            $rows = $page->get();

            foreach ($rows as $row) {
                yield $row;
            }

            $after = $rows === [] ? null : $rows[count($rows) - 1][$key];
        } while (count($rows) === self::BATCH);
    }
}
