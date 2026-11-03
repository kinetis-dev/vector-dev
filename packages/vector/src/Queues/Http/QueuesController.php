<?php

declare(strict_types=1);

namespace Kinetis\Vector\Queues\Http;

use Kinetis\Container\RequestScope;
use Kinetis\Http\Attributes\Delete;
use Kinetis\Http\Attributes\Get;
use Kinetis\Http\Attributes\Middleware;
use Kinetis\Http\Attributes\Post;
use Kinetis\Http\Attributes\RoutePrefix;
use Kinetis\Http\Responses\PlainTextResponse;
use Kinetis\Queue\ClearableQueueInterface;
use Kinetis\Queue\JobSerializer;
use Kinetis\Queue\QueueInterface;
use Kinetis\QueueFailedJobs\FailedJobRetrier;
use Kinetis\QueueFailedJobs\RetryResult;
use Kinetis\QueueFailedJobs\SqlFailedJobStore;
use Kinetis\QueueFailedJobs\StoredFailedJob;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Auth\ActorContext;
use Kinetis\Vector\Exception\ConflictException;
use Kinetis\Vector\Exception\ForbiddenOperationException;
use Kinetis\Vector\Exception\RecordNotFoundException;
use Kinetis\Vector\Queues\Auth\QueueDashboardPolicyInterface;
use Kinetis\Vector\Queues\FailedJobBrowser;
use Kinetis\Vector\Queues\FailedJobFilter;
use Kinetis\Vector\Queues\QueueDashboard;
use Kinetis\Vector\Queues\QueueNames;
use Kinetis\Vector\Store\AuditStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The queue dashboard's API, in Vector's own `vector` middleware group:
 * the application's authentication and VectorAccessInterface run first,
 * then {@see QueueDashboard} decides whether the dashboard is there at
 * all — a queue configured and the policy's view() — and every route
 * answers the Kernel's own 404 when it is not; the /failed routes answer
 * it too when kinetis/queue-failed-jobs is not wired. Then
 * QueueDashboardPolicyInterface decides each call. Sizes and clears go
 * to the application's bound QueueInterface; failed jobs are
 * kinetis/queue-failed-jobs' records, on the queues VECTOR_QUEUES names
 * only. A job's arguments leave the server only through
 * JobSerializer::redact(), and never reach the audit trail.
 *
 * Every route asks the gate and the policy before anything else, and
 * resolves what it reads or changes — the queue backend, the failed-job
 * store, the browser, the retrier — from the request scope only
 * afterwards: a method parameter would be built before the policy is
 * asked, so a refused caller would reach the database link (and see a
 * 500 when it is down) instead of a 403 — and a failed-jobs class would
 * be loaded where kinetis/queue-failed-jobs is not installed.
 */
#[RoutePrefix('/vector/queues')]
#[Middleware('@vector')]
final readonly class QueuesController
{
    /** The most ids one retry request takes. */
    public const int MAX_RETRY_BATCH = 50;

    /** The oldest prune age accepted: a hundred years. */
    private const int MAX_PRUNE_DAYS = 36_500;

    /** The audit trail's resource name for every dashboard entry, prefixed to keep it apart from application resources. */
    public const string AUDIT_RESOURCE = 'vector-queues';

    private const string ID = '/^[0-9a-f]{32}$/D';

    public function __construct(
        private QueueNames $queues,
        private QueueDashboardPolicyInterface $policy,
        private ActorContext $actor,
        private AuditStore $audit,
        private QueueDashboard $dashboard,
    ) {}

    /**
     * `failed` is null on every queue where kinetis/queue-failed-jobs is
     * not wired: there is no count to give, rather than none failed.
     *
     * @return array{data: list<array{name: string, size: int|null, clearable: bool, failed: int|null}>}
     */
    #[Get('/')]
    public function overview(ServerRequestInterface $request, RequestScope $scope): array
    {
        $failedJobs = $this->gate($request);
        $queue = self::backend($scope);
        $failed = $failedJobs ? self::resolve($scope, FailedJobBrowser::class)->failedCounts($this->queues->names) : null;
        $data = [];

        foreach ($this->queues->names as $name) {
            $data[] = [
                'name' => $name,
                'size' => self::size($queue, $name),
                'clearable' => $queue instanceof ClearableQueueInterface,
                'failed' => $failed === null ? null : $failed[$name] ?? 0,
            ];
        }

        return ['data' => $data];
    }

    /**
     * What the policy lets the current actor do, so the UI can leave out
     * what it would refuse: `clear` per watched queue, as a JSON object
     * whatever the names. It reads nothing but the policy, and hides
     * nothing on the server — every route still asks the policy itself.
     * Where kinetis/queue-failed-jobs is not wired there is no failed-job
     * action to take, so `retry`, `forget` and `prune` are false whatever
     * the policy says.
     *
     * @return array{view: true, retry: bool, forget: bool, prune: bool, clear: \stdClass}
     */
    #[Get('/permissions')]
    public function permissions(ServerRequestInterface $request): array
    {
        $failedJobs = $this->gate($request);
        // an object, not an array: a queue named "0" would make a PHP array encode as a JSON list
        $clear = new \stdClass();

        foreach ($this->queues->names as $name) {
            $clear->{$name} = $this->policy->clear($this->actor, $name);
        }

        return [
            'view' => true,
            'retry' => $failedJobs && $this->policy->retry($this->actor),
            'forget' => $failedJobs && $this->policy->forget($this->actor),
            'prune' => $failedJobs && $this->policy->prune($this->actor),
            'clear' => $clear,
        ];
    }

    /** @return array{data: list<array<string, mixed>>, nextCursor: string|null, hasMore: bool, limit: int} */
    #[Get('/failed')]
    public function failed(ServerRequestInterface $request, RequestScope $scope): array
    {
        $this->failedGate($request);
        $filter = FailedJobFilter::fromQueryParams($request->getQueryParams(), $this->queues);
        $page = self::resolve($scope, FailedJobBrowser::class)->page($filter, $this->queues->names);
        $last = $page['rows'] === [] ? null : $page['rows'][array_key_last($page['rows'])];

        return [
            'data' => array_map(static fn (array $row): array => [
                'id' => $row['id'],
                'connection' => $row['connection'],
                'queue' => $row['queue'],
                'class' => $row['class'],
                'attempts' => $row['attempts'],
                'maxAttempts' => $row['maxAttempts'],
                'exception' => ['class' => $row['exceptionClass'], 'message' => $row['exceptionMessage']],
                'failedAt' => self::time($row['failedAt']),
            ], $page['rows']),
            'nextCursor' => $page['hasMore'] && $last !== null ? FailedJobFilter::encodeCursor($last['failedAt'], $last['id']) : null,
            'hasMore' => $page['hasMore'],
            'limit' => $filter->limit,
        ];
    }

    /**
     * One record, every argument through JobSerializer::redact(). The
     * store keeps no stack trace, so `exception.trace` is always null.
     *
     * @return array{data: array<string, mixed>}
     */
    #[Get('/failed/{id}')]
    public function show(string $id, ServerRequestInterface $request, RequestScope $scope): array
    {
        $this->failedGate($request);
        $job = $this->watched(self::resolve($scope, SqlFailedJobStore::class), $id)->job;

        return ['data' => [
            'id' => $id,
            'connection' => $job->connection,
            'queue' => $job->queue,
            'class' => $job->class,
            'args' => JobSerializer::redact($job->class, $job->args),
            'attempts' => $job->attempts,
            'maxAttempts' => $job->maxAttempts,
            'exception' => ['class' => $job->exceptionClass, 'message' => $job->exceptionMessage, 'trace' => null],
            'failedAt' => self::time($job->failedAt),
        ]];
    }

    /**
     * Retries `{"ids": [...]}`, 1 to MAX_RETRY_BATCH of them, in order,
     * one result per distinct id: a repeated id is retried and answered
     * once, at its first position. A record on a queue the dashboard does not watch is
     * `not_found`, exactly as a missing one is. A failure of the
     * failed-job store stops the batch with a 500: the ids before it were
     * retried and audited, and its own id is audited with status `error`.
     *
     * @return array{data: list<array{id: string, status: string, class: string|null, queue: string|null, reason: string|null}>}
     */
    #[Post('/failed/retry')]
    public function retry(ServerRequestInterface $request, RequestScope $scope): array
    {
        $this->failedGate($request);
        $this->authorize($this->policy->retry($this->actor));
        $ids = self::body($request)['ids'] ?? null;

        // every id must have the store's own shape, or the whole batch is refused: none is retried, and none is echoed back
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_RETRY_BATCH
            || array_filter($ids, static fn (mixed $id): bool => is_string($id) && preg_match(self::ID, $id) === 1) !== $ids) {
            throw ValidationException::fromViolations([new Violation(['ids'], 'type_mismatch', 'must be a list of 1 to ' . self::MAX_RETRY_BATCH . ' failed-job ids, each 32 lowercase hex digits.')]);
        }

        $store = self::resolve($scope, SqlFailedJobStore::class);
        $retrier = self::resolve($scope, FailedJobRetrier::class);

        $results = [];

        foreach (array_values(array_unique($ids)) as $id) {
            try {
                $result = $this->find($store, $id) === null ? RetryResult::notFound($id) : $retrier->retry($id);
            } catch (\Throwable $e) {
                // only the store itself throws here — a failed push is a Failed result — and it may have failed after the push, with the job back on its queue: the batch stops, every id before it is already audited, and this one is audited as an error before the 500
                try {
                    $this->record([$id], 'queue:retry', ['status' => 'error', 'class' => null, 'queue' => null]);
                } catch (\Throwable) {
                    // the audit trail usually shares the store's database; the store's own failure is the one to report
                }

                throw $e;
            }

            $results[] = ['id' => $result->id, 'status' => $result->status->value, 'class' => $result->class, 'queue' => $result->queue, 'reason' => $result->reason];
            $this->record([$id], 'queue:retry', ['status' => $result->status->value, 'class' => $result->class, 'queue' => $result->queue]);
        }

        return ['data' => $results];
    }

    #[Delete('/failed/{id}', status: 204)]
    public function forget(string $id, ServerRequestInterface $request, RequestScope $scope): ResponseInterface
    {
        $this->failedGate($request);
        $this->authorize($this->policy->forget($this->actor));
        $store = self::resolve($scope, SqlFailedJobStore::class);
        $job = $this->watched($store, $id)->job;
        $store->forget($id);
        $this->record([$id], 'queue:forget', ['class' => $job->class, 'queue' => $job->queue]);

        return PlainTextResponse::create('', 204);
    }

    /**
     * Deletes every record older than `{"hours": n}` or `{"days": n}` —
     * exactly one of them, a whole number from 1 — through the store's
     * own prune(), which spans every queue, watched or not.
     *
     * @return array{data: array{deleted: int, olderThanSeconds: int}}
     */
    #[Post('/failed/prune')]
    public function prune(ServerRequestInterface $request, RequestScope $scope): array
    {
        $this->failedGate($request);
        $this->authorize($this->policy->prune($this->actor));
        $body = self::body($request);
        $given = array_values(array_intersect(['hours', 'days'], array_keys($body)));

        if (count($given) !== 1) {
            throw ValidationException::fromViolations([new Violation(['hours'], 'required', 'give exactly one of hours or days.')]);
        }

        $unit = $given[0];
        $max = $unit === 'days' ? self::MAX_PRUNE_DAYS : self::MAX_PRUNE_DAYS * 24;
        $value = $body[$unit];

        if (!is_int($value) || $value < 1 || $value > $max) {
            throw ValidationException::fromViolations([new Violation([$unit], 'out_of_range', "must be a whole number from 1 to {$max}.")]);
        }

        $seconds = $value * ($unit === 'days' ? 86_400 : 3_600);
        $deleted = self::resolve($scope, SqlFailedJobStore::class)->prune($seconds);
        $this->record(['failed'], 'queue:prune', ['olderThanSeconds' => $seconds, 'deleted' => $deleted]);

        return ['data' => ['deleted' => $deleted, 'olderThanSeconds' => $seconds]];
    }

    /**
     * Discards every job waiting on a watched queue. The body's `confirm`
     * must be the queue's name exactly (422 otherwise); a backend without
     * ClearableQueueInterface — SQS — is refused with 409.
     *
     * @return array{data: array{queue: string, cleared: int}}
     */
    #[Post('/{queue}/clear')]
    public function clear(string $queue, ServerRequestInterface $request, RequestScope $scope): array
    {
        // the gate and the policy first, as for every other mutation: a refused caller cannot tell watched queues from others
        $this->gate($request);

        // a segment outside kinetis/queue's grammar names no queue anywhere, so its 404 reveals nothing about which are watched, and the policy never sees it
        if (!QueueNames::isQueueName($queue)) {
            throw new RecordNotFoundException('No queue by that name is on this dashboard.');
        }

        $this->authorize($this->policy->clear($this->actor, $queue));

        if (!$this->queues->has($queue)) {
            throw new RecordNotFoundException("No queue named \"{$queue}\" is on this dashboard.");
        }

        if ((self::body($request)['confirm'] ?? null) !== $queue) {
            throw ValidationException::fromViolations([new Violation(['confirm'], 'confirmation_mismatch', 'must be the name of the queue to clear, exactly.')]);
        }

        $backend = self::resolve($scope, QueueInterface::class);

        if (!$backend instanceof ClearableQueueInterface) {
            throw new ConflictException('This queue backend cannot discard waiting jobs.');
        }

        $cleared = $backend->clear($queue);
        $this->record([$queue], 'queue:clear', ['cleared' => $cleared]);

        return ['data' => ['queue' => $queue, 'cleared' => $cleared]];
    }

    /**
     * The Kernel's own 404 unless the dashboard is there for this actor;
     * then whether failed jobs are too. Asks the policy's view(), and
     * builds nothing.
     *
     * @throws \Kinetis\Http\Routing\Exception\RouteNotFoundException
     */
    private function gate(ServerRequestInterface $request): bool
    {
        if (!$this->dashboard->available($this->policy, $this->actor)) {
            throw QueueDashboard::notFound($request);
        }

        return $this->dashboard->failedJobsWired();
    }

    /**
     * The gate, and the Kernel's own 404 where kinetis/queue-failed-jobs
     * is not wired: every /failed route asks this before anything else.
     *
     * @throws \Kinetis\Http\Routing\Exception\RouteNotFoundException
     */
    private function failedGate(ServerRequestInterface $request): void
    {
        if (!$this->gate($request)) {
            throw QueueDashboard::notFound($request);
        }
    }

    private function authorize(bool $allowed): void
    {
        if (!$allowed) {
            throw new ForbiddenOperationException('This account may not do that on the queue dashboard.');
        }
    }

    /**
     * What a route reads or changes, built only once the policy has
     * answered: resolved here rather than injected, since a method
     * parameter is built before the route runs.
     *
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function resolve(RequestScope $scope, string $id): object
    {
        $resolved = $scope->get($id);

        if (!$resolved instanceof $id) {
            throw new \LogicException("{$id} is bound to something else.");
        }

        return $resolved;
    }

    /** A record on a watched queue, or a 404 — the same for a malformed id, a missing record and one on another queue. */
    private function watched(SqlFailedJobStore $store, string $id): StoredFailedJob
    {
        return $this->find($store, $id) ?? throw RecordNotFoundException::of('failed jobs', $id);
    }

    private function find(SqlFailedJobStore $store, string $id): ?StoredFailedJob
    {
        $stored = preg_match(self::ID, $id) === 1 ? $store->find($id) : null;

        return $stored !== null && $this->queues->has($stored->job->queue) ? $stored : null;
    }

    /**
     * Who did what to which record: never a job argument.
     *
     * @param list<string> $recordIds
     * @param array<string, mixed> $changes
     */
    private function record(array $recordIds, string $event, array $changes): void
    {
        $this->audit->record(self::AUDIT_RESOURCE, $recordIds, $event, $changes, $this->actor->actorId(), $this->actor->impersonatorId);
    }

    /**
     * The bound queue backend, or null when building it throws — a
     * factory that cannot reach its broker, say: the overview then lists
     * every queue with no size and no clear, rather than failing whole.
     */
    private static function backend(RequestScope $scope): ?QueueInterface
    {
        try {
            $queue = $scope->get(QueueInterface::class);
        } catch (\Exception) {
            // a backend that cannot connect while it is built; an \Error is a programming mistake and surfaces
            return null;
        }

        if (!$queue instanceof QueueInterface) {
            throw new \LogicException(QueueInterface::class . ' is bound to something else.');
        }

        return $queue;
    }

    /** A backend that cannot answer — unreachable, not built, or with no count to give — has no size, rather than failing the overview. */
    private static function size(?QueueInterface $queue, string $name): ?int
    {
        if ($queue === null) {
            return null;
        }

        try {
            return $queue->size($name);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<array-key, mixed> */
    private static function body(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();

        try {
            $body = trim($raw) === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::fromViolations([new Violation([], 'invalid_json', 'The request body is not valid JSON.')]);
        }

        if (!is_array($body) || (array_is_list($body) && $body !== [])) {
            throw ValidationException::fromViolations([new Violation([], 'type_mismatch', 'The request body must be a JSON object.')]);
        }

        return $body;
    }

    private static function time(int $seconds): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $seconds);
    }
}
