<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use Kinetis\Config\Config;
use Kinetis\Container\AppScope;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Actions\ActionRequest;
use Kinetis\Vector\Actions\ActionResponse;
use Kinetis\Vector\Actions\VectorAction;
use Kinetis\Vector\Fields\Json;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\SettingsCompiler;
use Kinetis\Vector\Registry\VectorRegistry;
use Kinetis\Vector\Settings\SettingsGroup;
use Kinetis\Vector\Store\SettingsStore;
use stdClass;

/**
 * An action's and a settings group's `Json` and `KeyValue` inputs keep
 * their JSON objects: `{}` and `{"a": {}}` reach the handler, the group's
 * validate() and SettingsStore as stdClass, a list as a list, and a
 * key-value map as a stdClass of strings. The actions and the group are
 * the test's own: a run or a save that sees anything else is refused with
 * what it saw.
 */
final class ObjectInputsTest extends VectorTestCase
{
    /** What every test sends. */
    private const array SENT = ['doc' => '{}', 'nested' => '{"a":{}}', 'list' => '[1,{},[]]', 'map' => '{"k":"v"}'];

    #[\Override]
    protected function registerTestDoubles(AppScope $app, Config $config): void
    {
        parent::registerTestDoubles($app, $config);
        $registry = $app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        $data = $registry->toArray();
        $fields = array_map(static fn ($field): array => ResourceCompiler::compileLoose($field, [], ReportsItsInputs::class), ObjectInputs::fields());

        foreach ([ReportsItsInputs::class => ['reports-its-inputs', false], QueuedReportsItsInputs::class => ['queued-reports-its-inputs', true]] as $class => [$name, $queued]) {
            $data['resources']['products']['actions'][$name] = [
                'name' => $name, 'class' => $class, 'label' => $name, 'icon' => null, 'confirmation' => null, 'destructive' => false,
                'standalone' => true, 'queued' => $queued, 'chunkSize' => 100, 'showOnIndex' => true, 'showOnDetail' => false, 'fields' => $fields,
            ];
        }

        $data['settings'] += SettingsCompiler::compile([ObjectSettings::class], []);
        $app->instance(VectorRegistry::class, VectorRegistry::fromArray($data));
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->vectorQuery()->table('vector_settings')->where('group', '=', 'object-inputs')->delete();
        $this->vectorQuery()->table('vector_action_events')->whereIn('action', ['action:reports-its-inputs', 'action:queued-reports-its-inputs'])->delete();
        parent::tearDown();
    }

    public function testARestActionRunHandsTheHandlerObjects(): void
    {
        $answer = $this->client->post('/vector/r/products/actions/reports-its-inputs', body: ['fields' => self::sent()], headers: $this->adminHeaders())->assertOk()->json();
        self::assertIsArray($answer);
        self::assertSame(self::expected(), $answer['message']);
    }

    public function testAQueuedActionRunHandsTheHandlerObjects(): void
    {
        // the sync queue runs the job inside the request: a refused run fails it with a 422
        $this->client->post('/vector/r/products/actions/queued-reports-its-inputs', body: ['fields' => self::sent()], headers: $this->realAdminHeaders())->assertStatus(202);
    }

    public function testAnMcpActionRunHandsTheHandlerObjects(): void
    {
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'vector_run_action', 'arguments' => ['resource' => 'products', 'action' => 'reports-its-inputs', 'values' => self::sent()]]], JSON_THROW_ON_ERROR);
        $response = $this->client->raw('POST', '/mcp', $body, [...$this->adminHeaders(), 'MCP-Protocol-Version' => '2025-06-18', 'Content-Type' => 'application/json'])->assertOk()->json();
        self::assertIsArray($response);
        self::assertFalse($response['result']['isError'] ?? false, $response['result']['content'][0]['text'] ?? '');
        $answer = json_decode($response['result']['content'][0]['text'], true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        self::assertSame(self::expected(), $answer['message']);
    }

    public function testASettingsSaveKeepsObjectsInTheGroupTheStoreAndTheAnswer(): void
    {
        $saved = $this->client->request('PUT', '/vector/settings/object-inputs', body: self::sent(), headers: $this->adminHeaders())->assertOk()->body();
        $answer = json_decode($saved, false, 16, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $answer);

        foreach (self::SENT as $name => $json) {
            self::assertSame($json, json_encode($answer->data->values->{$name}, JSON_THROW_ON_ERROR), "{$name}: answered");
        }

        $store = $this->app->get(SettingsStore::class);
        self::assertInstanceOf(SettingsStore::class, $store);
        $registry = $this->app->get(VectorRegistry::class);
        self::assertInstanceOf(VectorRegistry::class, $registry);
        self::assertSame(self::expected(), ObjectInputs::report($store->values($registry->settingsGroup('object-inputs'))), 'read back by a consumer');
    }

    /** @return array<string, mixed> SENT decoded with its objects, as a JSON client sends them */
    private static function sent(): array
    {
        return array_map(static fn (string $json): mixed => json_decode($json, false, 16, JSON_THROW_ON_ERROR), self::SENT);
    }

    private static function expected(): string
    {
        return ObjectInputs::report(self::sent());
    }
}

/** The inputs every test double asks for, and how it reports what it was handed. */
final class ObjectInputs
{
    /** @return list<\Kinetis\Vector\Fields\Field> */
    public static function fields(): array
    {
        return [Json::make('doc'), Json::make('nested'), Json::make('list'), KeyValue::make('map')];
    }

    /** @param array<string, mixed> $values */
    public static function report(array $values): string
    {
        $types = [];

        foreach (['doc', 'nested', 'list', 'map'] as $name) {
            $types[] = $name . ': ' . get_debug_type($values[$name] ?? null) . ' ' . json_encode($values[$name] ?? null, JSON_THROW_ON_ERROR);
        }

        return implode('; ', $types);
    }
}

/** Answers what its inputs were. */
final class ReportsItsInputs extends VectorAction
{
    #[\Override]
    public function fields(): array
    {
        return ObjectInputs::fields();
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        return ActionResponse::message(ObjectInputs::report($request->fields));
    }
}

/** Queued; declines unless its inputs kept their objects. */
final class QueuedReportsItsInputs extends VectorAction
{
    #[\Override]
    public function fields(): array
    {
        return ObjectInputs::fields();
    }

    #[\Override]
    public function queued(): bool
    {
        return true;
    }

    #[\Override]
    public function handle(ActionRequest $request): ActionResponse
    {
        $report = ObjectInputs::report($request->fields);
        $expected = 'doc: stdClass {}; nested: stdClass {"a":{}}; list: array [1,{},[]]; map: stdClass {"k":"v"}';

        return $report === $expected ? ActionResponse::message('ok') : ActionResponse::danger($report);
    }
}

/** Refuses a save unless its inputs kept their objects. */
final class ObjectSettings extends SettingsGroup
{
    #[\Override]
    public function name(): string
    {
        return 'object-inputs';
    }

    public function fields(): array
    {
        return ObjectInputs::fields();
    }

    #[\Override]
    public function validate(array $values): array
    {
        $report = ObjectInputs::report($values);
        $expected = 'doc: stdClass {}; nested: stdClass {"a":{}}; list: array [1,{},[]]; map: stdClass {"k":"v"}';

        return $report === $expected ? [] : [new Violation(['doc'], 'kept_no_objects', $report)];
    }
}
