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
use Kinetis\Vector\Database\JsonDepth;
use Kinetis\Vector\Fields\DateRange;
use Kinetis\Vector\Fields\KeyValue;
use Kinetis\Vector\Fields\Tag;
use Kinetis\Vector\Query\ResourceService;
use Kinetis\Vector\Registry\VectorRegistry;
use Throwable;

/**
 * Turns a raw input value into the typed value a compiled field holds,
 * a related entity is loaded through the manager of the connection that
 * entity lives on, and only when the target's scope() lists it for this
 * user, so a value never points at a record its author may not see,
 * and runs the field's compiled rules. Shared by record writes, action
 * dialogs, settings pages and (in its query-string form) list
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
            // a JSON repeater that must hold rows holds none when cleared: its min() refuses that
            if (self::needsRows($field)) {
                throw self::rowCount($field['repeater']);
            }

            if (($field['nullable'] ?? false) === true) {
                return null;
            }

            throw new InputViolation(new Violation([], 'null_not_allowed', 'may not be null.'));
        }

        return match ($field['type']) {
            'belongsTo' => $this->related($field, $raw),
            'manyToMany' => $this->relatedList($field, $raw),
            'keyValue' => $this->keyValue($field, $raw),
            'json' => self::keepsObjects($field) ? self::document($field, $raw) : self::json($raw),
            'select', 'country' => self::select($field, $raw),
            'booleanGroup' => self::flags($field, $raw),
            'tag' => self::tags($field, $raw),
            'repeater' => $this->rows($field, $raw),
            'dateRange' => $this->range($field, $raw),
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
     * belongsTo or select resolves to the key or option value, not an
     * entity or an enum case. A multiple select's cell is its list, as
     * JSON (`["a","b"]`, what an export writes) or separated by `|`.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    public function fromQuery(array $field, string $raw): mixed
    {
        if ($field['type'] === 'select' || $field['type'] === 'country') {
            return ($field['multiple'] ?? false) === true ? self::optionList($field, self::cellList($raw)) : self::option($field, $raw);
        }

        // an imported cell holds the flags as the JSON object an export writes
        if ($field['type'] === 'booleanGroup') {
            return self::flags($field, json_decode($raw, true));
        }

        if ($field['type'] === 'currency') {
            return self::currency($field, $raw);
        }

        // an imported cell holds a date range as an ISO 8601 interval, `from/to`, as an export writes it
        if ($field['type'] === 'dateRange') {
            $ends = explode(DateRange::SEPARATOR, $raw);

            return count($ends) === 2 ? ['from' => trim($ends[0]), 'to' => trim($ends[1])] : throw self::mismatch('a date range as from/to');
        }

        // a cell of tags, as JSON or separated by `|`, as a multiple select's
        if ($field['type'] === 'tag') {
            return array_map(strval(...), self::cellList($raw));
        }

        // a JSON repeater's cell holds its rows as JSON text
        if ($field['type'] === 'repeater') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && array_is_list($decoded) ? $decoded : throw self::mismatch('a JSON list of rows');
        }

        // an imported cell holds the object as JSON text; a property that keeps objects reads {} as one
        if ($field['type'] === 'keyValue') {
            $decoded = json_decode($raw, !self::keepsObjects($field));

            return is_array($decoded) || $decoded instanceof \stdClass ? $decoded : throw self::mismatch('a JSON object of text values');
        }

        // read as deep as any field may nest (JsonDepth::MOST); a deeper cell's refusal names no limit, and the caller
        // that knows the resource gives it its field's (JsonDepth::limited()), as RecordInput refuses a shallower one
        if ($field['type'] === 'json') {
            try {
                $decoded = json_decode($raw, !self::keepsObjects($field), JsonDepth::MOST + 1, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw $e->getCode() === JSON_ERROR_DEPTH ? new InputViolation(JsonDepth::tooDeep(null)) : self::mismatch('a JSON object or array');
            }

            return is_array($decoded) || $decoded instanceof \stdClass ? $decoded : throw self::mismatch('a JSON object or array');
        }

        if ($field['type'] === 'belongsTo') {
            $target = $this->registry->resource($field['relation']['resource']);

            return $this->identifier($target->primaryKeyType(), $raw);
        }

        return match ($field['valueType'] ?? 'string') {
            'int' => preg_match('/^-?\d{1,18}$/D', $raw) === 1 ? (int) $raw : throw self::mismatch('an integer'),
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

        // a date range with neither end is no value, which required() refuses
        if (($field['type'] ?? null) === 'dateRange' && is_array($raw) && self::blank($raw['from'] ?? null) && self::blank($raw['to'] ?? null)) {
            $raw = null;
        }

        foreach ($field['rules'] as $rule) {
            $class = $rule['constraint'];

            if (!is_a($class, Constraint::class, true)) {
                continue;
            }

            // NotBlank is the "required" rule: a number or a boolean that arrived is present
            if (is_string($class) && str_ends_with($class, '\\NotBlank') && (is_int($raw) || is_float($raw) || is_bool($raw))) {
                continue;
            }

            // and a list or an object (a multiple select, tags, rows, a document) is present when it holds something;
            // an empty one, {} included, is refused with the very violation NotBlank gives a blank string
            $checked = $raw;

            if (is_string($class) && str_ends_with($class, '\\NotBlank') && (is_array($raw) || $raw instanceof \stdClass)) {
                if ((array) $raw !== []) {
                    continue;
                }

                $checked = '';
            }

            $violation = (new $class(...$rule['args']))->validate($checked);

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
            if (is_int($raw)) {
                return $raw;
            }

            return is_string($raw) && preg_match('/^[1-9]\d{0,17}$/D', $raw) === 1 ? (int) $raw : throw self::mismatch('an identifier');
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
     * One option of a select, as the value it is stored as: the option's
     * own value (a string or an int). A JSON body's value must have the
     * option's JSON type ($strict); a query string or a CSV cell is text,
     * matched by its text.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    public static function option(array $field, mixed $raw, bool $strict = false): int|string
    {
        if (is_int($raw) || is_string($raw)) {
            $text = $strict ? $raw : trim((string) $raw);

            foreach ($field['options'] ?? [] as $option) {
                if ($strict ? $option['value'] === $text : (string) $option['value'] === $text) {
                    return $option['value'];
                }
            }
        }

        $values = array_column($field['options'] ?? [], 'value');

        throw new InputViolation(new Violation([], 'invalid_choice', 'is not one of the allowed values.', ['choices' => count($values) <= 50 ? $values : []]));
    }

    /**
     * Options of a multiple select: each known, deduplicated, and kept in
     * the order the options are declared, whatever order they came in.
     *
     * @param array<string, mixed> $field
     * @param list<mixed> $raw
     * @return list<int|string>
     * @throws InputViolation
     */
    public static function optionList(array $field, array $raw, bool $strict = false): array
    {
        $chosen = [];

        foreach ($raw as $index => $value) {
            try {
                $chosen[(string) self::option($field, $value, $strict)] = true;
            } catch (InputViolation $e) {
                throw new InputViolation($e->violation->under((string) $index));
            }
        }

        $list = [];

        foreach ($field['options'] as $option) {
            if (isset($chosen[(string) $option['value']])) {
                $list[] = $option['value'];
            }
        }

        return $list;
    }

    /**
     * A select's value: one option, typed as its property stores it (an
     * enum case for an enum property), or a list of them for a multiple
     * one. An empty list is null where the column allows it.
     *
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private static function select(array $field, mixed $raw): mixed
    {
        if (($field['multiple'] ?? false) === true) {
            if (!is_array($raw) || !array_is_list($raw)) {
                throw self::mismatch('a list of option values');
            }

            $list = self::optionList($field, $raw, true);

            return $list === [] && ($field['nullable'] ?? false) === true ? null : $list;
        }

        $value = self::option($field, $raw instanceof BackedEnum ? $raw->value : $raw, true);
        $enum = $field['enum'] ?? null;

        if ($enum !== null) {
            /** @var class-string<BackedEnum> $enum */
            return $enum::from($value);
        }

        return $value;
    }

    /**
     * A flag group's value: an object of its option keys to true or
     * false, every option present in the result; a key left out is false.
     * A key that is no option is invalid_choice under that key, a value
     * that is not a boolean a type_mismatch.
     *
     * @param array<string, mixed> $field
     * @return array<string, bool>
     * @throws InputViolation
     */
    public static function flags(array $field, mixed $raw): array
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw self::mismatch('an object of option keys to true or false');
        }

        $keys = array_column($field['options'] ?? [], 'value');
        $flags = array_fill_keys($keys, false);

        foreach ($raw as $key => $on) {
            $key = (string) $key;

            if (!in_array($key, $keys, true)) {
                throw new InputViolation(new Violation([$key], 'invalid_choice', 'is not one of the options.', ['choices' => $keys]));
            }

            $flags[$key] = is_bool($on) ? $on : throw new InputViolation(new Violation([$key], 'type_mismatch', 'must be true or false.'));
        }

        return $flags;
    }

    /**
     * Tags as stored: a list of strings, each trimmed, empty ones dropped,
     * duplicates dropped ignoring case (the first spelling kept), in their
     * order. A tag that is not text or is too long is refused at its
     * index, every one of them; so is a list longer than the field allows.
     * An empty list is null where the column allows it.
     *
     * @param array<string, mixed> $field
     * @return list<string>|null
     * @throws InputViolation
     */
    private static function tags(array $field, mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::mismatch('a list of tags');
        }

        $maxLength = (int) ($field['tags']['maxLength'] ?? Tag::MAX_LENGTH);
        $maxTags = (int) ($field['tags']['maxTags'] ?? Tag::MAX_TAGS);
        $violations = [];
        $texts = [];

        foreach ($raw as $index => $tag) {
            if (!is_string($tag)) {
                $violations[] = new Violation([$index], 'type_mismatch', 'must be text.');
            } elseif (mb_strlen(Tag::trim($tag)) > $maxLength) {
                $violations[] = new Violation([$index], 'too_long', "may be at most {$maxLength} characters.", ['max' => $maxLength]);
            } else {
                $texts[] = $tag;
            }
        }

        if ($violations !== []) {
            throw InputViolation::of($violations);
        }

        $list = Tag::normalize($texts);

        if (count($list) > $maxTags) {
            throw new InputViolation(new Violation([], 'too_many_entries', "may hold at most {$maxTags} tags.", ['max' => $maxTags]));
        }

        return $list === [] && ($field['nullable'] ?? false) === true ? null : $list;
    }

    /**
     * A JSON repeater's rows: a list of objects, as many as the field
     * allows, each holding only the fields the repeater declares. Every
     * row field is converted and checked by its own rules, and stored as
     * the plain value a JSON document holds (an option's value, a date's
     * text). Every problem is reported at its row and field.
     *
     * @param array<string, mixed> $field
     * @return list<array<string, mixed>>
     * @throws InputViolation
     */
    private function rows(array $field, mixed $raw): array
    {
        $repeater = $field['repeater'];

        if (!is_array($raw) || !array_is_list($raw)) {
            throw self::mismatch('a list of rows');
        }

        if (count($raw) < $repeater['min'] || count($raw) > $repeater['max']) {
            throw self::rowCount($repeater);
        }

        $rowFields = array_column($repeater['rowFields'], null, 'name');
        $rows = [];
        $violations = [];

        foreach ($raw as $index => $row) {
            [$rows[], $found] = $this->row($rowFields, $index, $row);
            array_push($violations, ...$found);
        }

        if ($violations !== []) {
            throw InputViolation::of($violations);
        }

        return $rows;
    }

    /**
     * A date range: `{from, to}`, each a day or an instant as the range
     * holds them, or no range. Both ends are set or neither is (no range,
     * where the properties may be empty), and `to` is never before
     * `from`. Each problem is reported at its end.
     *
     * @param array<string, mixed> $field
     * @return array{from: Date|DateTimeImmutable, to: Date|DateTimeImmutable}|null
     * @throws InputViolation
     */
    private function range(array $field, mixed $raw): ?array
    {
        self::rangeShape($raw);

        $from = $raw['from'] ?? null;
        $to = $raw['to'] ?? null;

        if (self::blank($from) && self::blank($to)) {
            return ($field['nullable'] ?? false) === true ? null : throw new InputViolation(new Violation([], 'null_not_allowed', 'may not be null.'));
        }

        if (self::blank($from) || self::blank($to)) {
            $missing = self::blank($from) ? 'from' : 'to';

            throw new InputViolation(new Violation([$missing], 'range_incomplete', 'is required when the other end of the range is set.'));
        }

        $kind = ['type' => $field['range']['kind']];
        $violations = [];
        $start = $this->end($kind, $from, 'from', $violations);
        $end = $this->end($kind, $to, 'to', $violations);

        if ($start === null || $end === null || $violations !== []) {
            throw $violations === [] ? self::mismatch('a date range') : InputViolation::of($violations);
        }

        // both as the text a JSON document holds them: a day, or a UTC instant to the millisecond, which sort as they compare
        $first = (string) self::plain($kind, $start);

        if ((string) self::plain($kind, $end) < $first) {
            throw new InputViolation(new Violation(['to'], 'range_reversed', 'must not be before the start of the range.', ['from' => $first]));
        }

        return ['from' => $start, 'to' => $end];
    }

    /**
     * A date range is sent as an object whose keys are only `from` and `to`.
     *
     * @phpstan-assert array<array-key, mixed> $raw
     * @psalm-assert array<array-key, mixed> $raw
     * @throws InputViolation
     */
    private static function rangeShape(mixed $raw): void
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw self::mismatch('an object of from and to');
        }

        $unexpected = array_diff(array_map(strval(...), array_keys($raw)), ['from', 'to']);

        if ($unexpected !== []) {
            throw new InputViolation(new Violation([reset($unexpected)], 'unexpected_field', 'is not an end of a date range; send from and to.'));
        }
    }

    /**
     * One end of a date range as its kind holds it, or null with what is
     * wrong with it added to $violations at that end.
     *
     * @param array{type: mixed} $kind
     * @param list<Violation> $violations
     * @param-out list<Violation> $violations
     */
    private function end(array $kind, mixed $value, string $end, array &$violations): Date|DateTimeImmutable|null
    {
        try {
            return $kind['type'] === 'date' ? $this->day($value) : $this->instant($value);
        } catch (InputViolation $e) {
            $violations[] = $e->violation->under($end);

            return null;
        }
    }

    /** Whether one end of a date range is left empty. */
    private static function blank(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * Whether a field is a JSON repeater whose min() asks for rows, so that
     * no value (null) is too few of them rather than an empty column.
     *
     * @param array<string, mixed> $field
     */
    public static function needsRows(array $field): bool
    {
        return $field['type'] === 'repeater' && ($field['repeater']['mode'] ?? null) === 'json' && ($field['repeater']['min'] ?? 0) > 0;
    }

    /** @param array<string, mixed> $repeater */
    private static function rowCount(array $repeater): InputViolation
    {
        return new InputViolation(new Violation([], 'count_out_of_range', "must hold {$repeater['min']} to {$repeater['max']} rows.", ['min' => $repeater['min'], 'max' => $repeater['max']]));
    }

    /**
     * One row of a JSON repeater: its values in the order the fields are
     * declared, a field it leaves out as null, or what is wrong with it.
     *
     * @param array<string, array<string, mixed>> $rowFields
     * @return array{0: array<string, mixed>, 1: list<Violation>}
     */
    private function row(array $rowFields, int $index, mixed $row): array
    {
        if (!is_array($row) || ($row !== [] && array_is_list($row))) {
            return [[], [new Violation([$index], 'type_mismatch', 'must be an object of field values.')]];
        }

        $violations = [];
        $values = [];

        foreach (array_keys($row) as $key) {
            if (!isset($rowFields[(string) $key])) {
                $violations[] = new Violation([$index, (string) $key], 'unexpected_field', 'is not a field of this row.');
            }
        }

        foreach ($rowFields as $name => $sub) {
            // a field the row leaves out takes its default, as a new record's would
            $value = array_key_exists($name, $row) ? $row[$name] : (($sub['hasDefault'] ?? false) === true ? $sub['default'] : null);

            if ($value === null && ($sub['nullable'] ?? true) !== true) {
                $violations[] = new Violation([$index, $name], 'required', 'is required.');

                continue;
            }

            try {
                $values[$name] = self::plain($sub, $this->convert($sub, $value));
            } catch (InputViolation $e) {
                array_push($violations, ...array_map(static fn (Violation $v): Violation => $v->under($index, $name), $e->all()));

                continue;
            }

            foreach ($this->violationsOfRules($sub, $value) as $violation) {
                $violations[] = $violation->under($index, $name);
            }
        }

        return [$values, $violations];
    }

    /**
     * A converted value as a JSON document holds it: an option's value, an
     * instant (or a date field's day) as text.
     *
     * @param array<string, mixed> $field
     */
    private static function plain(array $field, mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeImmutable && $field['type'] === 'date' => $value->format('Y-m-d'),
            $value instanceof DateTimeImmutable => $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            $value instanceof Date => (string) $value,
            default => $value,
        };
    }

    /**
     * A cell holding a list: JSON (`["a","b"]`) or text separated by `|`.
     *
     * @return list<string|int>
     */
    private static function cellList(string $raw): array
    {
        $raw = trim($raw);

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);

            if (is_array($decoded) && array_is_list($decoded)) {
                return array_values(array_filter($decoded, static fn (mixed $v): bool => is_string($v) || is_int($v)));
            }
        }

        return array_values(array_filter(array_map('trim', explode('|', $raw)), static fn (string $v): bool => $v !== ''));
    }

    /**
     * Any JSON object or array, for an `array` property; an object
     * arrives as an associative array. A bare scalar is refused: the
     * column holds a document.
     *
     * @return array<array-key, mixed>
     * @throws InputViolation
     */
    private static function json(mixed $raw): array
    {
        if (!is_array($raw)) {
            throw self::mismatch('a JSON object or array');
        }

        return $raw;
    }

    /**
     * Whether a JSON or key-value field maps a property that keeps JSON
     * objects (`stdClass`, `array|stdClass`, nullable or not), so its value
     * is a stdClass rather than an associative array.
     *
     * @param array<string, mixed> $field
     */
    public static function keepsObjects(array $field): bool
    {
        return isset($field['jsonObjects']);
    }

    /**
     * Any JSON object or array, for a property that keeps objects: every
     * object a stdClass and every array a list, at any depth, so `{}`
     * stays an object. An associative array (a value that came through a
     * path that decodes objects to arrays) is read as the object it was;
     * an empty array is a list, except as the whole value of a `stdClass`
     * property, which holds an object only.
     *
     * @param array<string, mixed> $field
     * @return \stdClass|list<mixed>
     * @throws InputViolation
     */
    private static function document(array $field, mixed $raw): \stdClass|array
    {
        $objectOnly = $field['jsonObjects'] === 'object';

        if (!is_array($raw) && !$raw instanceof \stdClass) {
            throw self::mismatch($objectOnly ? 'a JSON object' : 'a JSON object or array');
        }

        if ($objectOnly && $raw === []) {
            return new \stdClass();
        }

        $tree = self::objectTree($raw);

        return $objectOnly && !$tree instanceof \stdClass ? throw self::mismatch('a JSON object') : $tree;
    }

    /**
     * A decoded JSON value with every object a stdClass and every array a
     * list. A key starting with NUL is refused: PHP cannot hold it as a
     * property, so it would not be stored.
     *
     * @throws InputViolation
     */
    private static function objectTree(mixed $value): mixed
    {
        if (!is_array($value) && !$value instanceof \stdClass) {
            return $value;
        }

        $members = $value instanceof \stdClass ? get_object_vars($value) : $value;
        $tree = [];

        foreach ($members as $key => $member) {
            if (str_starts_with((string) $key, "\0")) {
                throw self::mismatch('a JSON document with no key starting with a NUL character');
            }

            $tree[$key] = self::objectTree($member);
        }

        if (is_array($value) && array_is_list($tree)) {
            return $tree;
        }

        $object = new \stdClass();

        foreach ($tree as $key => $member) {
            $object->{(string) $key} = $member;
        }

        return $object;
    }

    /**
     * An object of text values for a JSON column: keys trimmed and
     * non-empty, a number or boolean kept as its text, nothing nested.
     * An empty object is null where the column allows it. A property that
     * keeps objects receives a stdClass, `{}` when empty.
     *
     * @param array<string, mixed> $field
     * @return array<string, string>|\stdClass|null
     * @throws InputViolation
     */
    private function keyValue(array $field, mixed $raw): array|\stdClass|null
    {
        // a stdClass is an object even when its keys read as 0, 1, 2
        $object = $raw instanceof \stdClass;
        $raw = $object ? get_object_vars($raw) : $raw;

        if (!is_array($raw) || (!$object && $raw !== [] && array_is_list($raw))) {
            throw self::mismatch('an object of text values');
        }

        $max = $field['props']['maxEntries'] ?? 50;

        if (count($raw) > $max) {
            throw new InputViolation(new Violation([], 'too_many_entries', "may hold at most {$max} entries.", ['max' => $max]));
        }

        $map = [];

        foreach ($raw as $key => $value) {
            // a NUL is refused before trim(), which would strip it
            $key = str_contains((string) $key, "\0") ? '' : trim((string) $key);

            if ($key === '' || mb_strlen($key) > KeyValue::KEY_LENGTH) {
                throw new InputViolation(new Violation([], 'invalid_key', 'has a key that is empty, holds a NUL character or is longer than ' . KeyValue::KEY_LENGTH . ' characters.'));
            }

            if ($value !== null && !is_scalar($value)) {
                throw new InputViolation(new Violation([], 'type_mismatch', "has a value under \"{$key}\" that is not text."));
            }

            $map[$key] = match ($value) {
                true => 'true',
                false => 'false',
                default => (string) $value,
            };
        }

        if ($map === [] && ($field['nullable'] ?? false) === true) {
            return null;
        }

        return self::keepsObjects($field) ? (object) $map : $map;
    }

    /**
     * @param array<string, mixed> $field
     * @throws InputViolation
     */
    private function scalar(array $field, mixed $raw): mixed
    {
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
        if (!is_string($raw) || !self::isInstant($raw)) {
            throw self::mismatch('an ISO 8601 date or date-time');
        }

        try {
            return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            throw new InputViolation(new Violation([], 'invalid_date', 'is not a valid date.'));
        }
    }

    /**
     * YYYY-MM-DD, then an optional time (hh:mm, :ss, .ffffff) and an
     * optional zone (Z, ±hh:mm or ±hhmm). The shape is matched first and
     * the time and the zone it isolates are checked on their own; neither
     * can contain the other's characters, so the split is unambiguous.
     */
    private static function isInstant(string $raw): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?<time>[T ][\d:.]+)?(?<zone>Z|[+-][\d:]+)?$/D', $raw, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            return false;
        }

        return ($m['time'] === null || preg_match('/^[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?$/D', $m['time']) === 1)
            && ($m['zone'] === null || preg_match('/^(?:Z|[+-]\d{2}:?\d{2})$/D', $m['zone']) === 1);
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
