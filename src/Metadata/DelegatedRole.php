<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Json;

/**
 * A delegated targets role: a named {@see Role} that a targets metadata file
 * hands part of its namespace to, restricted to the paths (or path-hash
 * prefixes) it is allowed to provide.
 */
final class DelegatedRole extends Role
{
    /**
     * @param list<string> $keyids
     * @param list<string> $paths
     * @param list<string> $pathHashPrefixes
     */
    public function __construct(
        public readonly string $name,
        array $keyids,
        int $threshold,
        public readonly array $paths = [],
        public readonly bool $terminating = false,
        public readonly array $pathHashPrefixes = [],
    ) {
        parent::__construct($keyids, $threshold);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            name: Json::string($data, 'name'),
            keyids: self::keyids($data),
            threshold: Json::int($data, 'threshold'),
            paths: isset($data['paths']) ? self::stringList($data, 'paths') : [],
            terminating: isset($data['terminating']) ? Json::bool($data, 'terminating') : false,
            pathHashPrefixes: isset($data['path_hash_prefixes']) ? self::stringList($data, 'path_hash_prefixes') : [],
        );
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $values = [];

        foreach (Json::list($data, $key) as $value) {
            if (! is_string($value)) {
                throw new RepositoryException(\sprintf('"%s" must be a list of strings.', $key));
            }
            $values[] = $value;
        }

        return $values;
    }
}
