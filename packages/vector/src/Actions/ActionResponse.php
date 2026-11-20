<?php

declare(strict_types=1);

namespace Kinetis\Vector\Actions;

/**
 * What the client does after an action ran: show a message, show a
 * failure, navigate, open a URL, or save a file. One instance is one of
 * those; `affected` counts the records the action touched.
 *
 * An action may also declare which of the records it was handed it
 * actually acted on (withRecords()): ActionRunner then logs and audits
 * those alone, and `affected` is their count. Without a declaration
 * every record handed to the action is logged and audited. The
 * declaration is not part of the response the client sees.
 */
final readonly class ActionResponse
{
    /**
     * @param 'message'|'danger'|'redirect'|'url'|'download'|'job' $type
     * @param list<int|string>|null $records the records the action declares it acted on; null when it declares none
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
        public ?array $records = null,
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

    /** A file: saved by the browser, or, from a queued action's finish(), left on its job with $message. */
    public static function download(string $contents, string $filename, string $contentType = 'text/csv; charset=utf-8', ?string $message = null): self
    {
        return new self('download', $message, null, $contents, $filename, $contentType, 0);
    }

    public function withAffected(int $affected): self
    {
        return new self($this->type, $this->message, $this->url, $this->contents, $this->filename, $this->contentType, $affected, $this->jobId, $this->records);
    }

    /**
     * Declares the records, of those handed to the action, that it acted
     * on: only these are logged and audited, and `affected` becomes their
     * count. An empty list declares that it acted on none. An identifier
     * the action was not handed is ignored.
     *
     * @param list<int|string> $ids
     */
    public function withRecords(array $ids): self
    {
        return new self($this->type, $this->message, $this->url, $this->contents, $this->filename, $this->contentType, $this->affected, $this->jobId, $ids);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'message' => $this->message, 'url' => $this->url, 'affected' => $this->affected, 'jobId' => $this->jobId];
    }
}
