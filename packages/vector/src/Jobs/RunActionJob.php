<?php

declare(strict_types=1);

namespace Kinetis\Vector\Jobs;

use Kinetis\Queue\Job;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\ActionRunner;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\Registry\VectorRegistry;

/**
 * A queued action: the selection is resolved to ids inside the job and
 * handled in chunks, each chunk its own ActionRequest and flush, with
 * progress written after every chunk. Each chunk, and finish(), runs on a
 * request scope of its own (JobContext::inFreshScope()), the resource read
 * anew as the admin sees it: no request-scoped cache a chunk filled
 * carries over into the next. Delivery is at least once, so an
 * action that is queued must tolerate a chunk running twice. Each chunk's
 * ActionRequest carries the job's id; after the last, the action's
 * finish() runs once, and a `download` it answers is the job's file.
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
            [$runner, $visible] = self::resolved($context, $descriptor);

            $ids = $runner->resolveSelection($visible, $this->selection, $compiled['standalone']);

            if ($ids === [] && !$compiled['standalone']) {
                throw new JobFailedException('The selection matches no records any more.');
            }

            $context->jobs->progress($this->jobId, 0, count($ids));
            $processed = 0;
            $affected = 0;
            $last = null;

            foreach ($compiled['standalone'] ? [[]] : array_chunk($ids, $compiled['chunkSize']) as $chunk) {
                $response = $context->inFreshScope(function (JobContext $fresh) use ($descriptor, $chunk): ActionResponse {
                    [$runner, $visible] = self::resolved($fresh, $descriptor);

                    return $runner->runNow($visible, $this->action, $chunk, $this->fields, $this->jobId);
                });

                if ($response->type === 'danger') {
                    throw new JobFailedException($response->message ?? 'The action declined.');
                }

                $processed += count($chunk);
                $affected += $response->affected;
                $last = $response->message;
                $context->jobs->progress($this->jobId, $processed);
            }

            $finished = $context->inFreshScope(function (JobContext $fresh) use ($descriptor): ?ActionResponse {
                [$runner, $visible] = self::resolved($fresh, $descriptor);

                return $runner->finish($visible, $this->action, $this->fields, $this->jobId);
            });

            if ($finished?->type === 'danger') {
                throw new JobFailedException($finished->message ?? 'The action declined.');
            }

            $last = $finished->message ?? $last;
            $result = ['affected' => $affected, 'message' => $last];

            if ($finished?->type === 'download' && $finished->contents !== null) {
                $name = $finished->filename ?? 'download';

                return ['result' => $result + ['fileName' => $name], 'processed' => $processed, 'file' => $finished->contents, 'fileName' => $name, 'fileType' => $finished->contentType, 'notice' => $last];
            }

            return ['result' => $result, 'processed' => $processed, 'notice' => $last];
        });
    }

    /**
     * The action runner of $context's scope, and $descriptor as the admin
     * who started the job sees it there.
     *
     * @return array{ActionRunner, ResourceDescriptor}
     */
    private static function resolved(JobContext $context, ResourceDescriptor $descriptor): array
    {
        $runner = $context->get(ActionRunner::class);

        if (!$runner instanceof ActionRunner) {
            throw new \LogicException('ActionRunner did not resolve.');
        }

        $service = $context->get(ResourceService::class);

        if (!$service instanceof ResourceService) {
            throw new \LogicException('ResourceService did not resolve.');
        }

        return [$runner, $service->visible($descriptor)];
    }
}
