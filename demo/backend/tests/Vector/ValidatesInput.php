<?php

declare(strict_types=1);

namespace App\Tests\Vector;

use App\Auth\DemoActor;
use Kinetis\Cache\DiscoveryContext;
use Kinetis\Container\RequestScope;
use Kinetis\DatabaseBridge\OrmMetadata;
use Kinetis\Http\CurrentUserInterface;
use Kinetis\Orm\Metadata\MetadataRegistry;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Registry\ResourceCompiler;
use Kinetis\Vector\Registry\ResourceDescriptor;
use Kinetis\Vector\VectorResource;
use Kinetis\Vector\Write\FieldInput;
use Kinetis\Vector\Write\RecordInput;

/**
 * A resource compiled on its own and a payload checked against it by the
 * write side's own input, in a request scope of its own: what a create or
 * an update would answer, without registering the resource.
 *
 * @phpstan-require-extends VectorTestCase
 */
trait ValidatesInput
{
    /** @return array<string, mixed> the compiled resource */
    private function compiled(VectorResource $resource, string $slug): array
    {
        $metadata = MetadataRegistry::fromArray(new DiscoveryContext(dirname(__DIR__, 2))->compiled(OrmMetadata::class));

        return (new ResourceCompiler($metadata, [$resource::class]))->compile()[$slug];
    }

    /**
     * @param array<string, mixed> $compiled
     * @param array<array-key, mixed> $payload
     * @param array<string, mixed> $current
     * @return array<string, mixed>|list<array{0: list<int|string>, 1: string}> the converted values, or each violation's path and code
     */
    private function recordInput(array $compiled, array $payload, bool $partial = false, array $current = []): array
    {
        $scope = $this->inputScope();

        try {
            $input = $scope->get(RecordInput::class);
            self::assertInstanceOf(RecordInput::class, $input);

            return $input->validate(new ResourceDescriptor($compiled), $payload, $partial, $current);
        } catch (ValidationException $e) {
            return array_map(static fn (Violation $v): array => [$v->path, $v->code], $e->violations);
        } finally {
            $scope->dispose();
        }
    }

    /**
     * @param array<string, array<string, mixed>> $fields field name => loose compiled field
     * @param array<array-key, mixed> $payload
     * @return array<string, mixed>|list<array{0: list<int|string>, 1: string}> the converted values, or each violation's path and code
     */
    private function fieldInput(array $fields, array $payload): array
    {
        $scope = $this->inputScope();

        try {
            $input = $scope->get(FieldInput::class);
            self::assertInstanceOf(FieldInput::class, $input);

            return $input->validate($fields, $payload);
        } catch (ValidationException $e) {
            return array_map(static fn (Violation $v): array => [$v->path, $v->code], $e->violations);
        } finally {
            $scope->dispose();
        }
    }

    /** A request scope of its own, as an admin: the input reads relation scopes as the request's user. */
    private function inputScope(): RequestScope
    {
        $scope = $this->app->createRequestScope();
        $scope->instance(CurrentUserInterface::class, new DemoActor(1, 'Test', true));

        return $scope;
    }
}
