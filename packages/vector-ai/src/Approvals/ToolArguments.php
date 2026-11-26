<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Approvals;

use Kinetis\Mcp\KinetisMcpApplication;
use Kinetis\Mcp\McpDispatcher;
use Kinetis\Mcp\ToolDefinition;
use Kinetis\Validation\Exception\ValidationException;
use Kinetis\Validation\Hydrator;
use Kinetis\Validation\InputSource;
use Kinetis\Validation\JsonObject;
use stdClass;

/**
 * A tool's arguments checked the way McpDispatcher::callTool() checks
 * them, without calling the tool: the same plan (McpDispatcher::derivePlan()),
 * the same Hydrator paths for each argument, the same closed arguments
 * object, so the violations, and the `{"errors": [...]}` feedback the
 * model reads (KinetisMcpApplication::validationFeedback()), are the
 * ones an MCP client would get. ScopedMcpTool's conversion of the
 * model's arguments is mirrored too (hydration()), so what is checked
 * here is what the approval later dispatches.
 *
 * The dispatcher's resolution is private; this follows kinetis/mcp
 * v1.10's McpDispatcher::resolveFromPlan() line for line.
 */
final class ToolArguments
{
    /**
     * @param array<array-key, mixed> $inputs the model's arguments, as Neuron hands them over
     * @throws ValidationException with every violation the call has
     */
    public static function validate(ToolDefinition $tool, array $inputs): void
    {
        /** @var class-string $class McpRegistry registers a tool only on a class it found */
        $class = $tool->controllerClass;
        $plan = McpDispatcher::derivePlan(new \ReflectionClass($class)->getMethod($tool->controllerMethod));
        $arguments = self::hydration($inputs);
        $violations = [];
        $client = [];

        foreach ($plan as $param) {
            if ($param['isProgressReporter']) {
                continue;
            }

            $name = $param['name'];
            $client[$name] = true;

            if (array_key_exists($name, $arguments)) {
                $violations = [...$violations, ...self::violations($arguments[$name], $param)];

                continue;
            }

            if (!$param['hasDefault']) {
                $violations[] = Hydrator::requiredViolation([$name]);
            }
        }

        foreach (array_keys($arguments) as $name) {
            if (!isset($client[$name])) {
                $violations[] = Hydrator::unexpectedFieldViolation([$name]);
            }
        }

        if ($violations !== []) {
            throw ValidationException::fromViolations($violations);
        }
    }

    /**
     * The model's arguments as the dispatcher binds them: each member
     * re-read as JSON, as ScopedMcpTool does, then JsonTree-converted, as
     * KinetisMcpApplication::hydrationArguments() does.
     *
     * @param array<array-key, mixed> $inputs
     * @return array<string, mixed>
     */
    public static function hydration(array $inputs): array
    {
        $object = new stdClass();

        foreach ($inputs as $name => $value) {
            $object->{$name} = json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), false, flags: JSON_THROW_ON_ERROR);
        }

        return KinetisMcpApplication::hydrationArguments($object);
    }

    /**
     * @param array<string, mixed> $param one entry of McpDispatcher::derivePlan()
     * @return list<\Kinetis\Validation\Violation>
     */
    private static function violations(mixed $value, array $param): array
    {
        $name = (string) $param['name'];

        if (is_string($param['dtoClass'] ?? null)) {
            /** @var class-string $dtoClass */
            $dtoClass = $param['dtoClass'];

            return self::dtoViolations($value, $param, $name, $dtoClass);
        }

        /** @var list<array{class: class-string<\Kinetis\Validation\Constraint>, args: array<int|string, mixed>}> $constraints */
        $constraints = $param['constraints'];
        $scalarType = is_string($param['scalarType'] ?? null) ? $param['scalarType'] : null;

        [, $violations] = $param['objectMap'] === true
            ? Hydrator::resolveObjectMap([$name], $value, $param['allowsNull'] === true, $param['keepObjects'] === true, $constraints)
            : Hydrator::resolveScalar(InputSource::Json, [$name], $value, $scalarType, $param['allowsNull'] === true, $constraints);

        return $violations;
    }

    /**
     * The violations of $value for a parameter bound to the DTO $dtoClass.
     *
     * @param array<string, mixed> $param one entry of McpDispatcher::derivePlan()
     * @param class-string $dtoClass
     * @return list<\Kinetis\Validation\Violation>
     */
    private static function dtoViolations(mixed $value, array $param, string $name, string $dtoClass): array
    {
        if ($value === null) {
            return $param['allowsNull'] === true ? [] : [Hydrator::nullNotAllowedViolation([$name])];
        }

        if ($value instanceof JsonObject) {
            $value = $value->toArray();
        }

        if (!is_array($value)) {
            return [is_object($value) ? Hydrator::notAnInstanceViolation([$name], $dtoClass) : Hydrator::objectExpectedViolation([$name], $value)];
        }

        try {
            Hydrator::hydrate($dtoClass, $value, null, InputSource::Json);
        } catch (ValidationException $e) {
            return $e->violations;
        }

        return [];
    }
}
