<?php

declare(strict_types=1);

namespace App\Tests\Vector;

/**
 * vector_describe is a contract agents depend on: for every resource in
 * Fixtures/describe-7974585.json, recorded at commit 7974585, its answer
 * to an administrator is byte-identical to the recorded one, so the
 * metadata bag of none of those resources or their fields reaches an
 * agent through it. Orders' number has since declared `unique()`, enum
 * fields have become select fields, JSON fields JSON columns, and
 * products and tickets have gained a multiple select; the fixture holds
 * them.
 */
final class McpDescribeSnapshotTest extends VectorTestCase
{
    private const string FIXTURE = __DIR__ . '/Fixtures/describe-7974585.json';

    public function testEveryExistingDescriptionIsUnchanged(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->issue('1', true), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
        $index = $this->call($headers, 'vector_resources', [])['resources'];
        self::assertIsArray($index);
        $answers = [];

        foreach (array_column($index, 'slug') as $slug) {
            self::assertIsString($slug);
            $answers[$slug] = $this->call($headers, 'vector_describe', ['resource' => $slug]);
        }

        ksort($answers);
        $now = json_encode($answers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        if (!is_file(self::FIXTURE)) {
            file_put_contents(self::FIXTURE, $now);
            self::markTestIncomplete('recorded ' . self::FIXTURE);
        }

        $before = file_get_contents(self::FIXTURE);
        self::assertIsString($before);
        $recorded = json_decode($before, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($recorded);

        self::assertSame(array_keys($recorded), array_keys(array_intersect_key($answers, $recorded)), 'every resource of then is still described');
        self::assertSame($before, json_encode(array_intersect_key($answers, $recorded), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    /**
     * The tool's text, exactly as an agent receives it, decoded.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(array $headers, string $tool, array $arguments): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        $result = $response['result'] ?? null;
        self::assertIsArray($result);
        self::assertFalse($result['isError'] ?? false);
        $decoded = json_decode($result['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
