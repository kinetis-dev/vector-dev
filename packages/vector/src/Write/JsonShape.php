<?php

declare(strict_types=1);

namespace Kinetis\Vector\Write;

/**
 * The shape of a JSON document a write sends: an object stays an object,
 * even an empty one, and a list stays a list. The entity holds the
 * document as a PHP array, where `{}` and `[]` are the same value, so the
 * text the ORM writes for it can lose an object; this is the text that
 * keeps it, encoded the way the ORM encodes a JSON column.
 */
final class JsonShape
{
    private const int ENCODE = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** A JSON request body decoded with its objects kept as objects, or null when it is no object. */
    public static function document(string $body): ?\stdClass
    {
        try {
            $decoded = json_decode($body, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $decoded instanceof \stdClass ? $decoded : null;
    }

    /** A document as the column's text. */
    public static function text(mixed $document): string
    {
        return json_encode($document, self::ENCODE);
    }

    /**
     * Whether $document is $value with its shape: the same keys, values
     * and order once its objects are read as arrays, as the entity holds it.
     *
     * @param array<array-key, mixed> $value
     */
    public static function holds(mixed $document, array $value): bool
    {
        return json_decode(self::text($document), true, 512, JSON_THROW_ON_ERROR) === $value;
    }
}
