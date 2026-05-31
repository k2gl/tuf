<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Json;

/**
 * The set of key ids trusted to sign for a role, and how many of them must sign
 * before the role's metadata is considered authentic.
 */
class Role
{
    /** @param list<string> $keyids */
    public function __construct(
        public readonly array $keyids,
        public readonly int $threshold,
    ) {
        if ($threshold < 1) {
            throw new RepositoryException('Role threshold must be at least 1.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            keyids: self::keyids($data),
            threshold: Json::int($data, 'threshold'),
        );
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<string>
     */
    protected static function keyids(array $data): array
    {
        $keyids = [];

        foreach (Json::list($data, 'keyids') as $keyid) {
            if (!is_string($keyid)) {
                throw new RepositoryException('Role keyids must be strings.');
            }
            $keyids[] = $keyid;
        }

        return $keyids;
    }
}
