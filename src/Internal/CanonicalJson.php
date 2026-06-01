<?php

declare(strict_types=1);

namespace K2gl\Tuf\Internal;

use K2gl\Tuf\Exception\RepositoryException;
use stdClass;

/**
 * Canonical JSON as defined by securesystemslib and required by TUF for the
 * bytes that signatures are computed over.
 *
 * The rules are deliberately tiny: UTF-8 output, object keys sorted by byte
 * value, no insignificant whitespace, strings escape only backslash and double
 * quote, integers render as their decimal form, and floats are rejected. It is
 * NOT general-purpose JSON — it exists solely to reproduce, byte for byte, the
 * input a TUF repository signed.
 *
 * Input must be the structure produced by {@see json_decode()} with
 * `$associative = false`: JSON objects arrive as {@see \stdClass} and JSON
 * arrays as PHP lists, so an empty object and an empty array stay distinct.
 *
 * @see https://theupdateframework.github.io/specification/latest/#metaformat
 */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                throw new RepositoryException('Canonical JSON cannot encode a non-list array.');
            }

            return '[' . implode(',', array_map([self::class, 'encode'], $value)) . ']';
        }

        if ($value instanceof stdClass) {
            return self::encodeObject($value);
        }

        throw new RepositoryException('Canonical JSON cannot encode a ' . get_debug_type($value) . '.');
    }

    private static function encodeObject(stdClass $object): string
    {
        /** @var array<string, mixed> $members */
        $members = get_object_vars($object);
        uksort($members, 'strcmp');

        $parts = [];

        foreach ($members as $key => $member) {
            $parts[] = self::encode($key) . ':' . self::encode($member);
        }

        return '{' . implode(',', $parts) . '}';
    }
}
