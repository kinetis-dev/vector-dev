<?php

declare(strict_types=1);

namespace Kinetis\Vector\Testing;

use Kinetis\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * One answer of the REST contract, with assertions in Vector's terms:
 * records, pages, totals, validation errors on a field, queued jobs.
 * Every assertion returns $this, so they chain; raw() is the framework's
 * TestResponse for anything else.
 */
final readonly class VectorResponse
{
    public function __construct(private TestResponse $response) {}

    public function raw(): TestResponse
    {
        return $this->response;
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    /** @return array<array-key, mixed> */
    public function json(): array
    {
        $json = $this->response->json();
        Assert::assertIsArray($json, 'The response is not a JSON object: ' . $this->response->body());

        return $json;
    }

    public function assertStatus(int $status): self
    {
        $this->response->assertStatus($status);

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertUnauthenticated(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    /** A 422 naming $field, optionally with the violation code. */
    public function assertInvalid(string $field, ?string $code = null): self
    {
        $this->assertStatus(422);
        $errors = array_values(array_filter($this->json()['errors'] ?? [], static fn (mixed $e): bool => is_array($e) && ($e['path'] ?? null) === [$field]));
        Assert::assertNotSame([], $errors, "No validation error names \"{$field}\": " . $this->response->body());

        if ($code !== null) {
            Assert::assertContains($code, array_column($errors, 'code'), "\"{$field}\" failed, but not with {$code}.");
        }

        return $this;
    }

    /** @return array<string, mixed> the record a show, create or update answered */
    public function record(): array
    {
        $record = $this->json()['data'] ?? null;
        Assert::assertIsArray($record, 'The response carries no record.');
        Assert::assertArrayHasKey('id', $record, 'The response carries no record.');

        /** @var array<string, mixed> $record */
        return $record;
    }

    public function id(): int|string
    {
        $id = $this->record()['id'];
        Assert::assertTrue(is_int($id) || is_string($id));

        /** @var int|string $id */
        return $id;
    }

    /**
     * The record holds these values. A relation value ({id, label}) matches
     * an expected scalar by its id.
     *
     * @param array<string, mixed> $expected
     */
    public function assertRecord(array $expected): self
    {
        $record = $this->record();

        foreach ($expected as $field => $value) {
            Assert::assertArrayHasKey($field, $record, "The record has no \"{$field}\".");
            $actual = $record[$field];

            if (is_array($actual) && array_key_exists('id', $actual) && !is_array($value)) {
                $actual = $actual['id'];
            }

            Assert::assertEquals($value, $actual, "\"{$field}\" differs.");
        }

        return $this;
    }

    /** The record, or every listed record, lacks $field entirely (hidden or not serialized). */
    public function assertMissingField(string $field): self
    {
        $data = $this->json()['data'] ?? null;
        $records = is_array($data) && array_is_list($data) ? $data : [$data];

        foreach ($records as $record) {
            Assert::assertIsArray($record);
            Assert::assertArrayNotHasKey($field, $record);
        }

        return $this;
    }

    /** @return list<array<string, mixed>> the records a list answered */
    public function records(): array
    {
        $data = $this->json()['data'] ?? null;
        Assert::assertIsArray($data, 'The response carries no list.');
        Assert::assertTrue(array_is_list($data), 'The response carries no list.');

        /** @var list<array<string, mixed>> $data */
        return $data;
    }

    /** @return list<int|string> */
    public function ids(): array
    {
        /** @var list<int|string> */
        return array_column($this->records(), 'id');
    }

    public function assertTotal(int $total): self
    {
        $json = $this->json();
        Assert::assertFalse(array_key_exists('total', $json) && $json['total'] === null, 'The list is not counted (the resource is withoutCount()); assert hasMore instead.');
        Assert::assertSame($total, $json['total'] ?? null, 'The list total differs.');

        return $this;
    }

    /** Whether another page follows, on an uncounted (withoutCount()) or a cursor page. */
    public function assertHasMore(bool $hasMore = true): self
    {
        Assert::assertSame($hasMore, $this->json()['hasMore'] ?? null, $hasMore ? 'Another page should follow.' : 'No page should follow.');

        return $this;
    }

    /** @param list<int|string> $ids in order */
    public function assertIds(array $ids): self
    {
        Assert::assertEquals($ids, $this->ids(), 'The listed records differ.');

        return $this;
    }

    public function assertListed(int|string $id): self
    {
        Assert::assertContains($id, $this->ids(), "Record {$id} is not listed.");

        return $this;
    }

    public function assertNotListed(int|string $id): self
    {
        Assert::assertNotContains($id, $this->ids(), "Record {$id} is listed.");

        return $this;
    }

    /** Every listed record satisfies $check (a callable returning bool). */
    public function assertEveryRecord(callable $check): self
    {
        foreach ($this->records() as $record) {
            Assert::assertTrue((bool) $check($record), 'A listed record does not match: ' . json_encode($record));
        }

        return $this;
    }

    /** A permission a record or a resource answer carries. */
    public function assertPermission(string $name, bool $granted): self
    {
        $json = $this->json();
        Assert::assertSame($granted, $json['permissions'][$name] ?? null, "The \"{$name}\" permission differs.");

        return $this;
    }

    /** A schema answer (one resource) publishes $field. */
    public function assertHasField(string $field): self
    {
        Assert::assertContains($field, array_column($this->json()['fields'] ?? [], 'name'), "The descriptor does not publish \"{$field}\".");

        return $this;
    }

    /** A schema answer (one resource) does not publish $field. */
    public function assertLacksField(string $field): self
    {
        Assert::assertNotContains($field, array_column($this->json()['fields'] ?? [], 'name'), "The descriptor publishes \"{$field}\".");

        return $this;
    }

    /** A 202 that queued a job; returns the job's id for `VectorClient::job()`. */
    public function assertQueued(): int
    {
        $this->assertStatus(202);
        $json = $this->json();
        Assert::assertSame('job', $json['type'] ?? null, 'The answer is not a queued job.');
        Assert::assertIsInt($json['jobId'] ?? null);

        return $json['jobId'];
    }
}
