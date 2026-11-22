<?php

declare(strict_types=1);

namespace Kinetis\VectorAi\Approvals;

/**
 * An approval's id: 128 random bits in lowercase base32 (RFC 4648's
 * alphabet), 26 characters, what `vector_ai_approvals.id` stores. The
 * model, the stream and the endpoints name it with PREFIX in front
 * (`apr_…`); the prefix is never stored.
 */
final class ApprovalId
{
    public const string PREFIX = 'apr_';

    public const int LENGTH = 26;

    private const string ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    /** A new stored id. */
    public static function generate(): string
    {
        $bits = '';

        foreach (str_split(random_bytes(16)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        // 128 bits make 25 full characters and 3 bits over, padded with zeros
        $bits .= '00';
        $id = '';

        foreach (str_split($bits, 5) as $chunk) {
            $id .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $id;
    }

    /** The id as the model, the stream and the endpoints name it. */
    public static function public(string $id): string
    {
        return self::PREFIX . $id;
    }

    /** The stored id $public names, or null when it is no approval id at all. */
    public static function parse(string $public): ?string
    {
        return preg_match('/^' . self::PREFIX . '([a-z2-7]{' . self::LENGTH . '})$/D', $public, $m) === 1 ? $m[1] : null;
    }
}
