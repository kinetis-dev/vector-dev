<?php

declare(strict_types=1);

namespace Kinetis\Vector\Actions;

/**
 * What the client does after an action ran: show a message, show a
 * failure, navigate, open a URL, or save a file. One instance is one of
 * those; `affected` counts the records the action touched.
 */
final readonly class ActionResponse
{
    /**
     * @param 'message'|'danger'|'redirect'|'url'|'download'|'job' $type
     */
    private function __construct(
        public string $type,
        public ?string $message,
        public ?string $url,
        public ?string $contents,
        public ?string $filename,
        public ?string $contentType,
        public int $affected,
        public ?int $jobId = null,
    ) {}

    /** The action was queued; the client polls the job. */
    public static function queued(int $jobId, ?string $message = null): self
    {
        return new self('job', $message, null, null, null, null, 0, $jobId);
    }

    public static function message(string $message, int $affected = 0): self
    {
        return new self('message', $message, null, null, null, null, $affected);
    }

    public static function danger(string $message): self
    {
        return new self('danger', $message, null, null, null, null, 0);
    }

    /** A path inside the admin UI, such as `/r/orders/12`. */
    public static function redirect(string $path, ?string $message = null, int $affected = 0): self
    {
        return new self('redirect', $message, $path, null, null, null, $affected);
    }

    /** An absolute URL the browser opens in a new tab. */
    public static function openUrl(string $url, ?string $message = null): self
    {
        return new self('url', $message, $url, null, null, null, 0);
    }

    public static function download(string $contents, string $filename, string $contentType = 'text/csv; charset=utf-8'): self
    {
        return new self('download', null, null, $contents, $filename, $contentType, 0);
    }

    public function withAffected(int $affected): self
    {
        return new self($this->type, $this->message, $this->url, $this->contents, $this->filename, $this->contentType, $affected);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'message' => $this->message, 'url' => $this->url, 'affected' => $this->affected, 'jobId' => $this->jobId];
    }
}
