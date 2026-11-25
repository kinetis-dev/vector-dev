<?php

declare(strict_types=1);

namespace Kinetis\Vector\Database;

use Kinetis\Orm\OrmFactoryRegistry;
use Kinetis\Validation\Violation;
use Kinetis\Vector\Registry\ResourceDescriptor;

/**
 * How deeply a JSON value may nest, counted in nested arrays and objects
 * (the outermost one is the first level), from what each database
 * really stores: kinetis/persistence's ReportsJsonDepth, 100 levels on
 * MySQL, 31 on MariaDB before 12.2, 2499 on MariaDB 12.2 and later and
 * on PostgreSQL.
 *
 * A field's value is written twice: to the record's own column, on the
 * connection its entity lives on, and to `vector_audit`, on Vector's
 * connection, which wraps it in AUDIT_LEVELS more. It is also answered:
 * the framework encodes an answer at json_encode()'s default depth,
 * ANSWER_DEPTH, and the deepest answer (a record's audit trail) wraps a
 * value in ANSWER_LEVELS. forField() is the strictest of the three, so a
 * value it admits is stored, audited and answered on any pair of
 * connections. One helper, so every site agrees on the limit.
 *
 * One instance per worker, bound on AppScope: it reads each connection's
 * depth from its OrmFactory and from VectorDatabase, both request-neutral,
 * and holds nothing request-scoped.
 */
final readonly class JsonDepth
{
    /** The levels an audit row's changes wrap around a value: the entry's object, and an update's `{from, to}`. */
    public const int AUDIT_LEVELS = 2;

    /** The depth the framework's JSON answers are encoded at: json_encode()'s default. */
    public const int ANSWER_DEPTH = 512;

    /** The levels the deepest answer wraps around a value: an audit trail's `{data: [{changes: {field: {from, to}}}]}`. */
    public const int ANSWER_LEVELS = 5;

    /** The most levels a field's value nests on any database: what an answer can still carry. */
    public const int MOST = self::ANSWER_DEPTH - self::ANSWER_LEVELS;

    public function __construct(
        private OrmFactoryRegistry $factories,
        private VectorDatabase $vector,
    ) {}

    /**
     * The levels a JSON column of $resource's own connection stores.
     *
     * @return positive-int
     */
    public function ofRecord(ResourceDescriptor $resource): int
    {
        return $this->factories->factoryFor($resource->entityClass())->jsonDepth();
    }

    /**
     * The levels a field value of $resource nests at most: within its
     * record's column, within the audit row on Vector's connection, and
     * within the deepest answer.
     *
     * @return positive-int
     */
    public function forField(ResourceDescriptor $resource): int
    {
        return max(1, min($this->ofRecord($resource), $this->vector->jsonDepth() - self::AUDIT_LEVELS, self::MOST));
    }

    /**
     * The levels a stored value of $resource may already nest: the deeper
     * of its record's connection and Vector's, so whatever either holds
     * reads back.
     *
     * @return positive-int
     */
    public function stored(ResourceDescriptor $resource): int
    {
        return max($this->ofRecord($resource), $this->vector->jsonDepth());
    }

    /** The levels of nested arrays and objects in $value: 0 for anything else. */
    public static function levels(mixed $value): int
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return 0;
        }

        $deepest = 0;

        foreach ($value as $member) {
            $deepest = max($deepest, self::levels($member));
        }

        return $deepest + 1;
    }

    /**
     * The refusal of a value nested deeper than $max levels, at the field
     * it is reported under. Without $max, the refusal of a value deeper
     * than any field holds, for a caller that does not know the
     * resource: whoever does gives it the field's own limit (limited()).
     */
    public static function tooDeep(?int $max): Violation
    {
        return $max === null
            ? new Violation([], 'too_deep', 'nests too deeply.')
            : new Violation([], 'too_deep', "nests deeper than {$max} levels.", ['max' => $max]);
    }

    /** $violation with the limit of a field of $resource (forField()) when it is a too_deep refusal that names none; any other unchanged. */
    public function limited(ResourceDescriptor $resource, Violation $violation): Violation
    {
        if ($violation->code !== 'too_deep' || array_key_exists('max', $violation->parameters)) {
            return $violation;
        }

        $limited = self::tooDeep($this->forField($resource));

        return new Violation($violation->path, $limited->code, $limited->message, $limited->parameters);
    }
}
