<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Queue\Job;
use Kinetis\Vector\Query\CsvWriter;
use Kinetis\Vector\Query\ListRequest;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Query\XlsxWriter;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Write\ValueConverter;

/**
 * An export too large for the inline path, or an XLSX one: the file is
 * built from keyset pages and stored on the job row for the actor to
 * download.
 */
final readonly class ExportJob implements Job
{
    /**
     * @param array<string, mixed> $params the list grammar as query parameters
     * @param string $format csv or xlsx
     */
    public function __construct(
        public int $jobId,
        public string $resource,
        public array $params,
        public string $actorId,
        public string $format = 'csv',
    ) {}

    public function handle(JobContext $context, VectorRegistry $registry, ValueConverter $converter): void
    {
        $descriptor = $registry->resource($this->resource);

        $context->run($this->jobId, $this->actorId, "Export of {$descriptor->label()}", function () use ($context, $descriptor, $converter): array {
            $reader = $context->get(ResourceService::class);

            if (!$reader instanceof ResourceService) {
                throw new \LogicException('ResourceService did not resolve.');
            }

            // the resource as the admin who started the job sees it
            $descriptor = $reader->visible($descriptor);

            $request = ListRequest::fromQueryParams($this->params, $descriptor, $converter)->unpaged();
            $fields = $request->columns ?? $descriptor->toArray()['table']['columns'];
            $rows = 0;
            $counting = (function () use ($reader, $descriptor, $request, $context, &$rows): \Generator {
                foreach ($reader->stream($descriptor, $request, $descriptor->exportQueueLimit()) as $record) {
                    $rows++;

                    if ($rows % 1000 === 0) {
                        $context->jobs->progress($this->jobId, $rows);
                    }

                    yield $record;
                }
            })();
            [$file, $type] = $this->format === 'xlsx'
                ? [XlsxWriter::document($descriptor, $fields, $counting), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']
                : [CsvWriter::document($descriptor, $fields, $counting), 'text/csv; charset=utf-8'];
            $name = $descriptor->slug() . '-' . gmdate('Ymd-His') . '.' . ($this->format === 'xlsx' ? 'xlsx' : 'csv');

            return ['result' => ['rows' => $rows, 'fileName' => $name], 'processed' => $rows, 'file' => $file, 'fileName' => $name, 'fileType' => $type, 'notice' => "{$rows} rows are ready to download."];
        });
    }
}
