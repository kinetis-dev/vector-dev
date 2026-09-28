<?php

declare(strict_types=1);

namespace Kinetis\Vector\Testing;

use Kinetis\Testing\TestClient;

/**
 * Request builders for one resource: one method per endpoint of the REST
 * contract, named after what it does, each answering a VectorResponse.
 */
final readonly class ResourceClient
{
    /** @param array<string, string> $headers */
    public function __construct(
        private TestClient $client,
        private array $headers,
        private string $base,
    ) {}

    /**
     * A page of the list grammar.
     *
     * @param array<string, mixed> $filter field => value, or field => [op => value]
     * @param array<string, mixed> $extra any other list parameter: group, summaries, columns, cursor, trashed…
     */
    public function list(array $filter = [], string $sort = '', string $q = '', int $page = 1, int $perPage = 25, array $extra = []): VectorResponse
    {
        $query = array_filter(['filter' => $filter, 'sort' => $sort, 'q' => $q], static fn (mixed $v): bool => $v !== [] && $v !== '');

        return $this->get($this->base, [...$query, 'page' => $page, 'perPage' => $perPage, ...$extra]);
    }

    public function show(int|string $id): VectorResponse
    {
        return $this->get("{$this->base}/{$id}");
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): VectorResponse
    {
        return new VectorResponse($this->client->post($this->base, $values, $this->headers));
    }

    /** @param array<string, mixed> $values */
    public function update(int|string $id, array $values, int|string|null $version = null): VectorResponse
    {
        $headers = $version === null ? $this->headers : [...$this->headers, 'If-Match' => (string) $version];

        return new VectorResponse($this->client->patch("{$this->base}/{$id}", $values, $headers));
    }

    public function delete(int|string $id, bool $force = false): VectorResponse
    {
        return new VectorResponse($this->client->delete("{$this->base}/{$id}" . ($force ? '?force=1' : ''), $this->headers));
    }

    public function restore(int|string $id): VectorResponse
    {
        return $this->post("{$this->base}/{$id}/restore", []);
    }

    /**
     * delete, restore, forceDelete or update, over ids or every record a selection matches.
     *
     * @param list<int|string> $ids
     * @param array{filter?: array<string, mixed>, q?: string}|null $all
     * @param array<string, mixed> $fields the values of an update
     */
    public function batch(string $action, array $ids = [], ?array $all = null, array $fields = []): VectorResponse
    {
        return $this->post("{$this->base}/batch", array_filter(['action' => $action, 'ids' => $ids, 'all' => $all, 'fields' => $fields], static fn (mixed $v): bool => $v !== [] && $v !== null));
    }

    /**
     * The list's inline changeset: different values for different records, in one transaction.
     *
     * @param list<array{id: int|string, version?: int, values: array<string, mixed>}> $changes
     */
    public function changes(array $changes): VectorResponse
    {
        return $this->post("{$this->base}/changes", ['changes' => $changes]);
    }

    /**
     * @param list<int|string> $ids
     * @param array<string, mixed> $fields the action dialog's values
     * @param array{filter?: array<string, mixed>, q?: string}|null $all
     */
    public function action(string $name, array $ids = [], array $fields = [], ?array $all = null): VectorResponse
    {
        return $this->post("{$this->base}/actions/{$name}", array_filter(['ids' => $ids, 'all' => $all, 'fields' => $fields], static fn (mixed $v): bool => $v !== null));
    }

    /** @param array<string, mixed> $query the list grammar */
    public function related(int|string $id, string $relation, array $query = []): VectorResponse
    {
        return $this->get("{$this->base}/{$id}/{$relation}", $query);
    }

    /** @param list<int|string> $ids */
    public function attach(int|string $id, string $relation, array $ids): VectorResponse
    {
        return $this->post("{$this->base}/{$id}/{$relation}/attach", ['ids' => $ids]);
    }

    /** @param list<int|string> $ids */
    public function detach(int|string $id, string $relation, array $ids): VectorResponse
    {
        return $this->post("{$this->base}/{$id}/{$relation}/detach", ['ids' => $ids]);
    }

    /** @param array<string, string> $depends the form's values a dependent choice list narrows by */
    public function options(string $field, string $search = '', array $depends = []): VectorResponse
    {
        return $this->get("{$this->base}/fields/{$field}/options", array_filter(['q' => $search, 'depends' => $depends], static fn (mixed $v): bool => $v !== []));
    }

    public function audit(int|string $id): VectorResponse
    {
        return $this->get("{$this->base}/{$id}/audit");
    }

    /** Puts back what one history entry changed. */
    public function revert(int|string $id, int $entryId): VectorResponse
    {
        return $this->post("{$this->base}/{$id}/audit/{$entryId}/revert", []);
    }

    /** @param array<string, mixed> $query the list grammar; `format` is csv unless it names another */
    public function export(array $query = []): VectorResponse
    {
        return $this->get("{$this->base}/export", ['format' => 'csv', ...$query]);
    }

    /** Uploads $contents for a file or image field; the answer's `key` is the value to write. */
    public function upload(string $field, string $contents, string $filename = 'upload.bin'): VectorResponse
    {
        $boundary = 'vector' . bin2hex(random_bytes(8));
        $name = str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $filename); // as a browser encodes it
        $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$contents}\r\n--{$boundary}--\r\n";

        return new VectorResponse($this->client->raw('POST', "{$this->base}/fields/{$field}/upload", $body, [...$this->headers, 'Content-Type' => "multipart/form-data; boundary={$boundary}"]));
    }

    public function file(int|string $id, string $field): VectorResponse
    {
        return $this->get("{$this->base}/{$id}/files/{$field}");
    }

    /** @param array<string, mixed> $query */
    private function get(string $uri, array $query = []): VectorResponse
    {
        return new VectorResponse($this->client->get($uri, $query, $this->headers));
    }

    /** @param array<string, mixed> $body */
    private function post(string $uri, array $body): VectorResponse
    {
        return new VectorResponse($this->client->post($uri, $body, $this->headers));
    }
}
