<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Queue\Job;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Vector\Exception\VectorException;
use Kinetis\Vector\Import\CsvReader;
use Kinetis\Vector\Import\RowMapper;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Write\RecordWriter;

/**
 * A CSV import: every row becomes one create through the same writer a
 * form uses, so every rule, policy and hook applies. A row the writer
 * refuses lands in the failed-rows file with the reason; the rest are
 * created. Rows are processed one by one — a failure never rolls back
 * the rows before it — so a re-run must start from the failed-rows file.
 * A failure that is not about the row — the connection, the worker —
 * fails the job instead, at the row it reached.
 */
final readonly class ImportJob implements Job
{
    /** @param array<string, string> $mapping CSV header => field name */
    public function __construct(
        public int $jobId,
        public string $resource,
        public array $mapping,
        public string $actorId,
    ) {}

    public function handle(JobContext $context, VectorRegistry $registry, RowMapper $mapper): void
    {
        $descriptor = $registry->resource($this->resource);

        $context->run($this->jobId, $this->actorId, "Import into {$descriptor->label()}", function () use ($context, $descriptor, $mapper): array {
            $writer = $context->get(RecordWriter::class);

            if (!$writer instanceof RecordWriter) {
                throw new \LogicException('RecordWriter did not resolve.');
            }

            $service = $context->get(\Kinetis\Vector\Query\ResourceService::class);

            if (!$service instanceof \Kinetis\Vector\Query\ResourceService) {
                throw new \LogicException('ResourceService did not resolve.');
            }

            $managers = $context->get(EntityManagerRegistry::class);

            if (!$managers instanceof EntityManagerRegistry) {
                throw new \LogicException('EntityManagerRegistry did not resolve.');
            }

            // the resource as the admin who started the job sees it
            $descriptor = $service->visible($descriptor);

            $file = $context->jobs->file($this->jobId) ?? throw new \RuntimeException('The uploaded file is gone.');
            $reader = CsvReader::fromString($file['contents']);
            $context->jobs->progress($this->jobId, 0, $reader->rowCount());
            $created = 0;
            $failed = [];
            $processed = 0;

            foreach ($reader->rows() as $line => $row) {
                $processed++;

                try {
                    $writer->create($descriptor, $mapper->payload($descriptor, $this->mapping, $row));
                    $created++;
                } catch (ValidationException $e) {
                    $failed[] = [...$row, 'error' => implode('; ', array_map(static fn (\Kinetis\Validation\Violation $v): string => implode('.', array_map('strval', $v->path)) . ' ' . $v->message, $e->violations))];
                } catch (VectorException $e) {
                    $failed[] = [...$row, 'error' => $e->getMessage()]; // refused by a policy or a rule: this row's failure
                }

                if ($processed % 100 === 0) {
                    $context->jobs->progress($this->jobId, $processed);
                    // every row so far is flushed; the identity map need not hold them for the rows to come
                    $managers->managerFor($descriptor->entityClass())->clear();
                }
            }

            $context->jobs->clearFile($this->jobId);
            $failedCsv = $failed === [] ? null : CsvReader::toCsv([...$reader->headers(), 'error'], $failed);

            return [
                'result' => ['created' => $created, 'failed' => count($failed)],
                'processed' => $processed,
                'file' => $failedCsv,
                'fileName' => $failedCsv === null ? null : $descriptor->slug() . '-failed-rows.csv',
                'fileType' => $failedCsv === null ? null : 'text/csv; charset=utf-8',
                'notice' => sprintf('%d created, %d failed.', $created, count($failed)),
            ];
        });
    }
}
