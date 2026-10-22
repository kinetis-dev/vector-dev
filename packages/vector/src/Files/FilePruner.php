<?php

declare(strict_types=1);

namespace Kinetis\Vector\Files;

use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\QueryBuilder\Query;
use Kinetis\Vector\Database\VectorDatabase;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\RichText\Document;
use League\Flysystem\FilesystemOperator;
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
                if (in_array($field['type'], ['file', 'image'], true)) {
                    $count += $this->pruneFolder($filesystem, $resource, $name, $cutoff, $columns, $dryRun, $each);
                }
            }
        }

        return $count;
    }

    /**
     * Prunes the folder of one file or image field, `vector/{resource}/{field}/`.
     *
     * @param list<array{connection: string, table: string, column: string}> $columns
     * @param \Closure(string): void $each
     * @return int how many files were deleted, or would be
     */
    private function pruneFolder(FilesystemOperator $filesystem, ResourceDescriptor $resource, string $name, int $cutoff, array $columns, bool $dryRun, \Closure $each): int
    {
        $prefix = "vector/{$resource->slug()}/{$name}/";
        $embedded = null; // read once a folder holds a candidate
        $batch = [];
        $count = 0;

        foreach ($filesystem->listContents(rtrim($prefix, '/'), false) as $item) {
            if (!$this->isCandidate($item, $prefix, $cutoff)) {
                continue;
            }

            $key = $item->path();
            $embedded ??= $this->embedded($resource, $name, $prefix);

            if (!isset($embedded[$key])) {
                $batch[] = $key;
            }

            if (count($batch) >= self::BATCH) {
                $count += $this->deleteUnreferenced($filesystem, $batch, $columns, $dryRun, $each);
                $batch = [];
            }
        }

        return $count + $this->deleteUnreferenced($filesystem, $batch, $columns, $dryRun, $each);
    }

    /**
     * Whether $item is a file of the shape the upload endpoint issues,
     * directly under $prefix, and older than $cutoff.
     */
    private function isCandidate(StorageAttributes $item, string $prefix, int $cutoff): bool
    {
        $key = $item->path();

        return $item->isFile()
            && str_starts_with($key, $prefix)
            && preg_match(self::ISSUED, substr($key, strlen($prefix))) === 1
            && $this->modified($item) <= $cutoff;
    }

    /**
     * Deletes the keys of $batch no file or image column holds, or only
     * reports them on a dry run.
     *
     * @param list<string> $batch
     * @param list<array{connection: string, table: string, column: string}> $columns
     * @param \Closure(string): void $each
     * @return int how many files were deleted, or would be
     */
    private function deleteUnreferenced(FilesystemOperator $filesystem, array $batch, array $columns, bool $dryRun, \Closure $each): int
    {
        $count = 0;

        foreach ($this->unreferenced($batch, $columns) as $key) {
            if (!$dryRun) {
                $filesystem->delete($key);
            }

            $each($key);
            $count++;
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

        // the documents are read first, then the audit trail: each generator queries only once iterated
        foreach ([$this->documentValues($resource, $fieldName), $this->auditValues($resource)] as $values) {
            foreach ($values as $value) {
                foreach ($this->keysUnder($value, $prefix) as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * The keys under $prefix that $value is, or that it shows as a
     * rich-text document's images.
     *
     * @return list<string>
     */
    private function keysUnder(string $value, string $prefix): array
    {
        if (str_starts_with($value, $prefix)) {
            return [$value];
        }

        if (!str_contains($value, '"image"') && stripos($value, '<img') === false) { // a document, its slashes escaped or not
            return [];
        }

        return array_values(array_filter(
            $this->document->imageKeys($value),
            static fn (string $key): bool => str_starts_with($key, $prefix),
        ));
    }

    /**
     * The stored values of the rich-text fields of $resource that use
     * $fieldName for their images, on any row.
     *
     * @return \Generator<int, string>
     */
    private function documentValues(ResourceDescriptor $resource, string $fieldName): \Generator
    {
        foreach ($resource->fields() as $name => $field) {
            $column = $resource->columns()[$name] ?? null;

            if ($column === null || $field['type'] !== 'richtext' || ($field['richtext']['imageField'] ?? null) !== $fieldName) {
                continue;
            }

            $query = fn (): Query => $this->managers->manager($resource->connection())->builder()->table($resource->table())->where($column, '!=', null);

            foreach (self::chunks($query, $resource->primaryKeyColumn(), [$column]) as $row) {
                if (is_string($row[$column])) {
                    yield $row[$column];
                }
            }
        }
    }

    /**
     * Every string the audit trail of $resource holds, at any depth of
     * its recorded changes.
     *
     * @return \Generator<int, string>
     */
    private function auditValues(ResourceDescriptor $resource): \Generator
    {
        $audit = fn (): Query => $this->db->query()->table('vector_audit')->where('resource', '=', $resource->slug());

        foreach (self::chunks($audit, 'id', ['changes']) as $row) {
            $changes = json_decode((string) $row['changes'], true);

            if (!is_array($changes)) {
                continue;
            }

            $strings = [];
            array_walk_recursive($changes, static function (mixed $value) use (&$strings): void {
                if (is_string($value)) {
                    $strings[] = $value;
                }
            });

            yield from $strings;
        }
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
