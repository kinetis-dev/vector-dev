<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Persistence\Contract\MysqlLink;
use Kinetis\QueryBuilder\Query;

/**
 * The MCP index and description an agent works from: vector_resources
 * lists what this caller may see, vector_describe tells how to read and
 * write one resource — and what it says is what the writer enforces.
 */
final class McpDescribeTest extends VectorTestCase
{
    public function testTheIndexListsSlugLabelAndGroupOnly(): void
    {
        $index = $this->tool($this->mcp($this->adminHeaders()), 'vector_resources', [])['resources'];
        $bySlug = array_column($index, null, 'slug');

        self::assertSame(['slug' => 'products', 'label' => 'Products', 'group' => 'Catalog'], $bySlug['products']);
        foreach ($index as $entry) {
            self::assertSame(['slug', 'label', 'group'], array_keys($entry), 'the detail is vector_describe\'s');
        }
    }

    public function testADescriptionStatesWhatACallCanDoWithEachField(): void
    {
        $products = $this->tool($this->mcp($this->adminHeaders()), 'vector_describe', ['resource' => 'products']);
        $fields = array_column($products['fields'], null, 'name');

        self::assertSame(['field' => 'id', 'type' => 'int'], $products['key']);
        self::assertTrue($products['softDelete']);
        self::assertTrue($products['permissions']['create']);

        // written by a create or update, with the writer's rules
        self::assertSame(['create', 'update'], $fields['sku']['writable']);
        self::assertTrue($fields['sku']['required']);
        self::assertTrue($fields['sku']['unique']);
        self::assertSame(['NotBlank', 'MaxLength(40)'], $fields['sku']['rules']);
        self::assertContains('like', $fields['sku']['filter']);

        // choices, relations, money and defaults
        self::assertSame(['draft', 'active', 'archived'], array_column($fields['status']['options'], 'value'));
        self::assertSame(['kind' => 'belongsTo', 'resource' => 'categories'], $fields['category']['relation']);
        self::assertSame('currency', $fields['priceCents']['money']['currencyField']);
        self::assertFalse($fields['currency']['required'], 'a default fills it in');
        self::assertSame('EUR', $fields['currency']['default']);

        // what a value looks like, where the type does not say
        self::assertStringContainsString('ids', $fields['tags']['format']);
        self::assertStringContainsString('ProseMirror', $fields['description']['format']);
        self::assertStringContainsString('upload', $fields['imageKey']['format']);

        // depends on other fields
        self::assertSame([['field' => 'status', 'op' => 'eq', 'value' => 'active']], $fields['releasedAt']['requiredWhen']);

        // a field no call writes carries nothing about writing
        self::assertSame([], $fields['createdAt']['writable']);
        self::assertArrayNotHasKey('required', $fields['createdAt']);
        self::assertArrayNotHasKey('rules', $fields['id']);

        // the actions, with their dialogs
        $actions = array_column($products['actions'], null, 'name');
        self::assertTrue($actions['archive-products']['destructive']);
        $percent = array_column($actions['reprice-products']['fields'], null, 'name')['percent'];
        self::assertTrue($percent['required']);
    }

    public function testRequiredIsExactlyWhatTheWriterDemands(): void
    {
        $headers = $this->mcp($this->adminHeaders());
        $fields = array_column($this->tool($headers, 'vector_describe', ['resource' => 'products'])['fields'], null, 'name');
        $required = array_keys(array_filter($fields, static fn (array $f): bool => ($f['required'] ?? false) === true));
        $stamp = bin2hex(random_bytes(3));

        // a value for each required field, from its description alone
        $values = [];
        foreach ($required as $name) {
            $values[$name] = $this->valueFor($headers, $fields[$name], "{$name}-{$stamp}");
        }

        $created = $this->call($headers, 'vector_create', ['resource' => 'products', 'values' => $values]);
        self::assertFalse($created['isError'] ?? false, 'the required fields alone make a valid record: ' . ($created['content'][0]['text'] ?? ''));
        $id = json_decode($created['content'][0]['text'], true, 32, JSON_THROW_ON_ERROR)['data']['id'];

        try {
            // and each one is needed
            $dropped = end($required);
            self::assertIsString($dropped);
            $without = $values;
            unset($without[$dropped]);
            $refused = $this->call($headers, 'vector_create', ['resource' => 'products', 'values' => [...$without, 'sku' => "x-{$stamp}", 'slug' => "x-{$stamp}"]]);
            self::assertTrue($refused['isError']);
            self::assertStringContainsString($dropped, $refused['content'][0]['text']);
        } finally {
            (new Query($this->link()))->table('products')->where('id', '=', (int) $id)->delete();
        }
    }

    public function testADescriptionIsInTheCallersLanguage(): void
    {
        $german = $this->tool($this->mcp($this->headersIn('de')), 'vector_describe', ['resource' => 'products']);

        self::assertSame('Produkte', $german['label']);
        self::assertSame('Preis', array_column($german['fields'], 'label', 'name')['priceCents']);
    }

    public function testAnUnknownResourceIsAnError(): void
    {
        $unknown = $this->call($this->mcp($this->adminHeaders()), 'vector_describe', ['resource' => 'nope']);

        self::assertTrue($unknown['isError']);
    }

    /**
     * A value an agent could send for this field, knowing only its description.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $field
     */
    private function valueFor(array $headers, array $field, string $text): mixed
    {
        if (isset($field['options'])) {
            return $field['options'][0]['value'];
        }

        if (isset($field['relation'])) {
            $page = $this->tool($headers, 'vector_query', ['resource' => $field['relation']['resource'], 'perPage' => 1]);

            return $page['data'][0]['id'];
        }

        return match ($field['type']) {
            'boolean' => false,
            'number', 'money' => 1,
            'color' => '#123456',
            'date' => '2026-01-01',
            'datetime' => '2026-01-01T00:00:00.000Z',
            default => substr($text, 0, 30),
        };
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private function mcp(array $headers): array
    {
        return [...$headers, 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'];
    }

    /**
     * A tool's answer, decoded; fails when the tool reports an error.
     *
     * @param array<string, string> $headers
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function tool(array $headers, string $tool, array $arguments): array
    {
        $result = $this->call($headers, $tool, $arguments);
        self::assertFalse($result['isError'] ?? false, $result['content'][0]['text'] ?? '');
        $decoded = json_decode($result['content'][0]['text'], true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(array $headers, string $tool, array $arguments): array
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, $headers)->assertOk()->json();
        self::assertIsArray($response);
        self::assertArrayHasKey('result', $response, json_encode($response, JSON_THROW_ON_ERROR));

        return $response['result'];
    }

    private function link(): MysqlLink
    {
        $link = $this->app->get(MysqlLink::class);
        self::assertInstanceOf(MysqlLink::class, $link);

        return $link;
    }
}
