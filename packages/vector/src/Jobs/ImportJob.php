<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Authorization\Exception\AuthorizationException;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Queue\Job;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Import\CsvReader;
use Kinetis\Vector\Import\RowMapper;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Store\JobStore;
use Kinetis\Vector\Write\RecordWriter;

/**
 * A CSV import: every row becomes one create — or, updating by a key
 * column, one update of the record the key finds — through the same
 * writer a form uses, so every rule, policy, hook and audit entry
 * applies. A row the writer refuses lands in the failed-rows file with
 * the reason; the rest are written. Rows are processed one by one — a
 * failure never rolls back the rows before it — so a re-run must start
 * from the failed-rows file. A failure that is not about the row — the
 * connection, the worker — fails the job instead, at the row it reached.
 */
final readonly class ImportJob implements Job
{
    /**
     * @param array<string, string> $mapping CSV header => field name
     * @param string $mode create, update or upsert — validated by RowMapper::validateMapping()
     * @param string|null $key the field update and upsert match rows by
     */
    public function __construct(
        public int $jobId,
        public string $resource,
        public array $mapping,
        public string $actorId,
        public string $mode = 'create',
        public ?string $key = null,
    ) {}

    public function handle(JobContext $context, VectorRegistry $registry, RowMapper $mapper): void
    {
        $descriptor = $registry->resource($this->resource);

        $context->run($this->jobId, $this->actorId, "Import into {$descriptor->label()}", function () use ($context, $descriptor, $mapper): array {
            $writer = $context->get(RecordWriter::class);

            if (!$writer instanceof RecordWriter) {
                throw new \LogicException('RecordWriter did not resolve.');
            }

            $service = $context->get(ResourceService::class);

            if (!$service instanceof ResourceService) {
                throw new \LogicException('ResourceService did not resolve.');
            }

            $managers = $context->get(EntityManagerRegistry::class);

            if (!$managers instanceof EntityManagerRegistry) {
                throw new \LogicException('EntityManagerRegistry did not resolve.');
            }

            // the resource as the admin who started the job sees it
            $descriptor = $service->visible($descriptor);
            $manager = $managers->managerFor($descriptor->entityClass());

            $file = $context->jobs->file($this->jobId) ?? throw new JobFailedException('The uploaded file is gone.');
            $reader = CsvReader::fromString($file['contents']);
            $context->jobs->progress($this->jobId, 0, $reader->rowCount());
            $created = 0;
            $updated = 0;
            $failed = [];
            $processed = 0;

            foreach ($reader->rows() as $row) {
                $processed++;

                try {
                    if ($this->mode === 'create' || $this->key === null) {
                        $writer->create($descriptor, $mapper->payload($descriptor, $this->mapping, $row));
                        $created++;

                        continue;
                    }

                    // one indexed lookup through the resource's scope: a row updates only what this admin may list
                    $value = $mapper->keyOf($descriptor, $this->mapping, $row, $this->key)
                        ?? throw ValidationException::fromViolations([new Violation([$this->key], 'required', 'matches the row to a record and cannot be empty.')]);
                    $id = $service->findByKey($descriptor, $this->key, $value);

                    if ($id !== null) {
                        $writer->update($descriptor, (string) $id, $mapper->payload($descriptor, $this->mapping, $row, except: $this->key), null);
                        $updated++;
                    } elseif ($this->mode === 'upsert') {
                        $writer->create($descriptor, $mapper->payload($descriptor, $this->mapping, $row));
                        $created++;
                    } else {
                        throw new RecordNotFoundException("No " . mb_strtolower($descriptor->labelSingular()) . " has this {$this->key}."); // update only: the failed-rows file
                    }
                } catch (ValidationException $e) {
                    $failed[] = [...$row, 'error' => implode('; ', array_map(static fn (Violation $v): string => implode('.', array_map('strval', $v->path)) . ' ' . $v->message, $e->violations))];
                    // a refused row may still be pending — a failed flush keeps its insert, a hook's refusal
                    // the values already assigned — and the next row's flush would send it again
                    $manager->clear();
                } catch (VectorException | AuthorizationException $e) {
                    $failed[] = [...$row, 'error' => $e->getMessage()]; // refused by a policy or a rule: this row's failure
                    $manager->clear();
                }

                if ($processed % 100 === 0) {
                    $context->jobs->progress($this->jobId, $processed);
                    // every row so far is flushed; the identity map need not hold them for the rows to come
                    $manager->clear();
                }
            }

            $context->jobs->clearFile($this->jobId);
            $failedCsv = $failed === [] ? null : CsvReader::toCsv([...$reader->headers(), 'error'], $failed, JobStore::MAX_FILE_BYTES);

            return [
                'result' => ['created' => $created, 'updated' => $updated, 'failed' => count($failed)],
                'processed' => $processed,
                'file' => $failedCsv,
                'fileName' => $failedCsv === null ? null : $descriptor->slug() . '-failed-rows.csv',
                'fileType' => $failedCsv === null ? null : 'text/csv; charset=utf-8',
                'notice' => $this->mode === 'create'
                    ? sprintf('%d created, %d failed.', $created, count($failed))
                    : sprintf('%d created, %d updated, %d failed.', $created, $updated, count($failed)),
            ];
        });
    }
}
