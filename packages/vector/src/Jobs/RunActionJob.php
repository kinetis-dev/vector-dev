<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Queue\Job;
use Kinetis\Vector\Actions\ActionRunner;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * A queued action: the selection is resolved to ids inside the job and
 * handled in chunks, each chunk its own ActionRequest and flush, with
 * progress written after every chunk. Delivery is at least once, so an
 * action that is queued must tolerate a chunk running twice.
 */
final readonly class RunActionJob implements Job
{
    /**
     * @param array<string, mixed> $selection `{ids: [...]}` or `{all: {filter, q}}`
     * @param array<string, mixed> $fields the dialog's raw values
     */
    public function __construct(
        public int $jobId,
        public string $resource,
        public string $action,
        public array $selection,
        public array $fields,
        public string $actorId,
    ) {}

    public function handle(JobContext $context, VectorRegistry $registry): void
    {
        $descriptor = $registry->resource($this->resource);
        $compiled = $descriptor->action($this->action) ?? throw new JobFailedException("Unknown action {$this->action}.");

        $context->run($this->jobId, $this->actorId, "{$compiled['label']} on {$descriptor->label()}", function () use ($context, $descriptor, $compiled): array {
            $runner = $context->get(ActionRunner::class);

            if (!$runner instanceof ActionRunner) {
                throw new \LogicException('ActionRunner did not resolve.');
            }

            $service = $context->get(\Kinetis\Vector\Query\ResourceService::class);

            if (!$service instanceof \Kinetis\Vector\Query\ResourceService) {
                throw new \LogicException('ResourceService did not resolve.');
            }

            // the resource as the admin who started the job sees it
            $descriptor = $service->visible($descriptor);

            $ids = $runner->resolveSelection($descriptor, $this->selection, $compiled['standalone']);

            if ($ids === [] && !$compiled['standalone']) {
                throw new JobFailedException('The selection matches no records any more.');
            }

            $context->jobs->progress($this->jobId, 0, count($ids));
            $processed = 0;
            $affected = 0;
            $last = null;

            foreach ($compiled['standalone'] ? [[]] : array_chunk($ids, $compiled['chunkSize']) as $chunk) {
                $response = $runner->runNow($descriptor, $this->action, $chunk, $this->fields);

                if ($response->type === 'danger') {
                    throw new JobFailedException($response->message ?? 'The action declined.');
                }

                $processed += count($chunk);
                $affected += $response->affected;
                $last = $response->message;
                $context->jobs->progress($this->jobId, $processed);
            }

            return ['result' => ['affected' => $affected, 'message' => $last], 'processed' => $processed, 'notice' => $last];
        });
    }
}
