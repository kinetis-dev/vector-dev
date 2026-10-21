<?php

declare(strict_types=1);

namespace Kinetis\Vector\Http;

use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Http\Responses\FileResponse;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Jobs\StalledJobs;
use Kinetis\Vector\Store\JobStore;
use Psr\Http\Message\ResponseInterface;

/** The background jobs an admin started: progress to poll, and the file a finished one left. */
#[RoutePrefix('/vector')]
#[Middleware('@vector')]
final readonly class JobsController
{
    public function __construct(
        private JobStore $jobs,
        private CurrentUserInterface $user,
        private StalledJobs $stalled,
    ) {}

    /** @return array{data: list<array<string, mixed>>} */
    #[Get('/jobs')]
    public function index(): array
    {
        return ['data' => array_map($this->stalled->mark(...), $this->jobs->forActor((string) $this->user->id()))];
    }

    /** @return array{data: array<string, mixed>} */
    #[Get('/jobs/{id}')]
    public function show(string $id): array
    {
        return ['data' => $this->stalled->mark($this->owned($id))];
    }

    #[Get('/jobs/{id}/download')]
    public function download(string $id): ResponseInterface
    {
        $job = $this->owned($id);
        $file = $this->jobs->file($job['id']) ?? throw RecordNotFoundException::of('jobs', $id);

        // an attachment, never sniffed or run: the same policy a record's file download carries
        return FileResponse::fromContents($file['contents'], $file['type'], 200, $file['name'], [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /** @return array<string, mixed> */
    private function owned(string $id): array
    {
        $job = preg_match('/^[1-9]\d{0,17}$/D', $id) === 1 ? $this->jobs->find((int) $id) : null;

        if ($job === null || $job['actorId'] !== (string) $this->user->id()) {
            throw RecordNotFoundException::of('jobs', $id);
        }

        return $job;
    }
}
