<?php

declare(strict_types=1);

namespace K2gl\Tuf\Internal;

use K2gl\Tuf\Exception\RepositoryException;
use JsonException;

/**
 * Minimal typed accessors over a json_decode associative array. Every failure is
 * a {@see RepositoryException}, so callers never deal with raw type juggling.
 */
final class Json
{
    /** @return array<string, mixed> */
    public static function decodeObject(string $json): array
    {
        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RepositoryException('Metadata is not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (! is_array($data) || (array_is_list($data) && $data !== [])) {
            throw new RepositoryException('Expected a JSON object at the document root.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RepositoryException(\sprintf('Expected string at "%s".', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new RepositoryException(\sprintf('Expected integer at "%s".', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    public static function bool(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new RepositoryException(\sprintf('Expected boolean at "%s".', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function object(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            throw new RepositoryException(\sprintf('Expected object at "%s".', $key));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<mixed>
     */
    public static function list(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RepositoryException(\sprintf('Expected array at "%s".', $key));
        }

        /** @var list<mixed> $value */
        return $value;
    }
}
