<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

use BackedEnum;
use DateTimeImmutable;
use DateTimeZone;
use Kinetis\Container\RequestScope;
use Kinetis\Orm\Date;
use Kinetis\Orm\EntityManagerRegistry;
use Kinetis\Validation\Constraint;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Auth\PasswordHasherInterface;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Throwable;

/**
 * Turns a raw input value into the typed value a compiled field holds —
 * a related entity is loaded through the manager of the connection that
 * entity lives on, and only when the target's scope() lists it for this
 * user, so a value never points at a record its author may not see —
 * and runs the field's compiled rules. Shared by record writes, action
 * dialogs, settings pages and — in its query-string form — list
 * filters, so one conversion vocabulary serves every input Vector
 * accepts. Pure per call; the reader that answers the scope question is
 * resolved from the request on demand, since it takes this converter.
 */
final readonly class ValueConverter
{
    public function __construct(
        private VectorRegistry $registry,
        private EntityManagerRegistry $managers,
        private RequestScope $scope,
    ) {}

    /**
     * A JSON body value: typed as JSON types it (an int is an int).
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    public function convert(array $field, mixed $raw): mixed
    {
        if ($raw === null || ($raw === '' && ($field['valueType'] ?? 'string') !== 'string')) {
            if (($field['nullable'] ?? false) === true) {
                return null;
            }

            throw new InputViolation(new Violation([], 'null_not_allowed', 'may not be null.'));
        }

        return match ($field['type']) {
            'belongsTo' => $this->related($field, $raw),
            'manyToMany' => $this->relatedList($field, $raw),
            'keyValue' => $this->keyValue($field, $raw),
            'currency' => self::currency($field, $raw),
            'password' => $this->password($field, $raw),
            default => $this->scalar($field, $raw),
        };
    }

    /**
     * Whether a submitted value means "leave the stored password alone":
     * an empty value for a hashed field, which a form sends when the
     * admin typed nothing into it.
     *
     * @param array<string, mixed> $field
     */
    public static function keepsStoredPassword(array $field, mixed $raw): bool
    {
        return $field['type'] === 'password' && isset($field['props']['hasher']) && ($raw === null || $raw === '');
    }

    /**
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function password(array $field, mixed $raw): string
    {
        $plain = is_string($raw) ? $raw : throw self::mismatch('a string');
        $hasher = $field['props']['hasher'] ?? null;

        if (!is_string($hasher)) {
            return $plain;
        }

        $service = $this->scope->get($hasher);

        return $service instanceof PasswordHasherInterface
            ? $service->hash($plain)
            : throw new \LogicException("{$hasher} did not resolve to a PasswordHasherInterface; bind the application's hasher on AppScope in bootstrap.php.");
    }

    /**
     * A query-string value: text, parsed by the field's value type. A
     * belongsTo or enum resolves to the key or case value, not an entity.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    public function fromQuery(array $field, string $raw): mixed
    {
        $enum = $field['enum'] ?? null;

        if ($enum !== null) {
            /** @var class-string<BackedEnum> $enum */
            $case = $enum::tryFrom($raw) ?? (is_numeric($raw) ? $enum::tryFrom((int) $raw) : null);

            return $case === null ? throw new InputViolation(new Violation([], 'invalid_choice', 'is not one of the allowed values.')) : $case->value;
        }

        if ($field['type'] === 'currency') {
            return self::currency($field, $raw);
        }

        // an imported cell holds the object as JSON text
        if ($field['type'] === 'keyValue') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : throw self::mismatch('a JSON object of text values');
        }

        if ($field['type'] === 'belongsTo') {
            $target = $this->registry->resource($field['relation']['resource']);

            return $this->identifier($target->primaryKeyType(), $raw);
        }

        return match ($field['valueType'] ?? 'string') {
            'int' => preg_match('/^-?[0-9]{1,18}$/', $raw) === 1 ? (int) $raw : throw self::mismatch('an integer'),
            'float' => is_numeric($raw) ? (float) $raw : throw self::mismatch('a number'),
            'bool' => match (strtolower($raw)) {
                'true', '1', 'yes' => true,
                'false', '0', 'no' => false,
                default => throw self::mismatch('true or false'),
            },
            'timestamp' => $this->instant($raw),
            'date' => $this->day($raw),
            default => $raw,
        };
    }

    /**
     * @param array<string, mixed> $field
     * @return list<Violation>
     */
    public function violationsOfRules(array $field, mixed $raw): array
    {
        $violations = [];

        foreach ($field['rules'] as $rule) {
            $class = $rule['constraint'];

            if (!is_a($class, Constraint::class, true)) {
                continue;
            }

            // NotBlank is the "required" rule: a number or a boolean that arrived is present
            if (is_string($class) && str_ends_with($class, '\\NotBlank') && (is_int($raw) || is_float($raw) || is_bool($raw))) {
                continue;
            }

            $violation = (new $class(...$rule['args']))->validate($raw);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** @throws InputViolation */
    public function identifier(string $type, mixed $raw): int|string
    {
        if ($type === 'int') {
            return is_int($raw) ? $raw : (is_string($raw) && preg_match('/^[1-9][0-9]{0,17}$/', $raw) === 1 ? (int) $raw : throw self::mismatch('an identifier'));
        }

        return is_string($raw) && $raw !== '' ? $raw : throw self::mismatch('an identifier');
    }

    /**
     * A currency code among the field's choices, however it was cased.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private static function currency(array $field, mixed $raw): string
    {
        $code = is_string($raw) ? strtoupper(trim($raw)) : null;
        $codes = array_column($field['options'] ?? [], 'value');

        if ($code === null || !in_array($code, $codes, true)) {
            throw new InputViolation(new Violation([], 'invalid_choice', 'is not one of the allowed currencies.', ['choices' => count($codes) <= 20 ? $codes : []]));
        }

        return $code;
    }

    /**
     * An object of text values, stored as JSON text: keys trimmed and
     * non-empty, a number or boolean kept as its text, nothing nested.
     * An empty object is null where the column allows it.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function keyValue(array $field, mixed $raw): ?string
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw self::mismatch('an object of text values');
        }

        $max = $field['props']['maxEntries'] ?? 50;

        if (count($raw) > $max) {
            throw new InputViolation(new Violation([], 'too_many_entries', "may hold at most {$max} entries.", ['max' => $max]));
        }

        $map = [];

        foreach ($raw as $key => $value) {
            $key = trim((string) $key);

            if ($key === '' || mb_strlen($key) > KeyValue::KEY_LENGTH) {
                throw new InputViolation(new Violation([], 'invalid_key', 'has a key that is empty or longer than ' . KeyValue::KEY_LENGTH . ' characters.'));
            }

            if ($value !== null && !is_scalar($value)) {
                throw new InputViolation(new Violation([], 'type_mismatch', "has a value under \"{$key}\" that is not text."));
            }

            $map[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        if ($map === [] && ($field['nullable'] ?? false) === true) {
            return null;
        }

        return json_encode((object) $map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function scalar(array $field, mixed $raw): mixed
    {
        $enum = $field['enum'] ?? null;

        if ($enum !== null) {
            /** @var class-string<BackedEnum> $enum */
            $case = is_int($raw) || is_string($raw) ? $enum::tryFrom($raw) : null;

            return $case ?? throw new InputViolation(new Violation(
                [],
                'invalid_choice',
                'is not one of the allowed values.',
                ['choices' => array_map(static fn (BackedEnum $c): int|string => $c->value, $enum::cases())],
            ));
        }

        return match ($field['valueType']) {
            'int' => is_int($raw) ? $raw : throw self::mismatch('an integer'),
            'float' => is_int($raw) || is_float($raw) ? (float) $raw : throw self::mismatch('a number'),
            'bool' => is_bool($raw) ? $raw : throw self::mismatch('true or false'),
            'timestamp' => $this->instant($raw),
            'date' => $this->day($raw),
            default => is_string($raw) ? $raw : throw self::mismatch('a string'),
        };
    }

    /**
     * A calendar date for a `Kinetis\Orm\Date` property: exactly
     * YYYY-MM-DD, a day that exists. No time and no zone, so nothing
     * shifts it.
     *
     * @throws InputViolation
     */
    private function day(mixed $raw): Date
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw) !== 1) {
            throw self::mismatch('a date as YYYY-MM-DD');
        }

        try {
            return Date::fromString($raw);
        } catch (\InvalidArgumentException) {
            throw new InputViolation(new Violation([], 'invalid_date', 'is not a valid date.'));
        }
    }

    /** @throws InputViolation */
    private function instant(mixed $raw): DateTimeImmutable
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?(?:Z|[+-]\d{2}:?\d{2})?$/', $raw) !== 1) {
            throw self::mismatch('an ISO 8601 date or date-time');
        }

        try {
            return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InputViolation(new Violation([], 'invalid_date', 'is not a valid date.'));
        }
    }

    /**
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function related(array $field, mixed $raw): object
    {
        $target = $this->registry->resource($field['relation']['resource']);
        $id = $this->identifier($target->primaryKeyType(), $raw);
        $notFound = new InputViolation(new Violation([], 'not_found', "names no {$target->slug()} record.", ['id' => $id]));

        // a record outside the target's scope() answers as one that does not exist: no oracle across it
        if ($this->reader()->withinScope($target, [$id]) === []) {
            throw $notFound;
        }

        $entity = $this->managers->managerFor($target->entityClass())->repository($target->entityClass())->find($id);

        return $entity ?? throw $notFound;
    }

    /**
     * @param array<string, mixed> $field
     * @return list<object>
     * @throws InputViolation
     */
    private function relatedList(array $field, mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::mismatch('a list of identifiers');
        }

        if ($raw === []) {
            return [];
        }

        $target = $this->registry->resource($field['relation']['resource']);
        $ids = [];

        foreach ($raw as $value) {
            $ids[] = $this->identifier($target->primaryKeyType(), $value);
        }

        $ids = array_values(array_unique($ids));
        $found = count($this->reader()->withinScope($target, $ids)) === count($ids)
            ? $this->managers->managerFor($target->entityClass())->repository($target->entityClass())->query()->whereIn($target->primaryKeyField(), $ids)->get()
            : [];

        if (count($found) !== count($ids)) {
            throw new InputViolation(new Violation([], 'not_found', "names a {$target->slug()} record that does not exist."));
        }

        return $found;
    }

    /** The request's reader, for the scope a relation's target applies; resolved late, because it holds this converter. */
    private function reader(): ResourceService
    {
        $reader = $this->scope->get(ResourceService::class);

        return $reader instanceof ResourceService ? $reader : throw new \LogicException('ResourceService did not resolve.');
    }

    public static function mismatch(string $expected): InputViolation
    {
        return new InputViolation(new Violation([], 'type_mismatch', "must be {$expected}."));
    }
}
