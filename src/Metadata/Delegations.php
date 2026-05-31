<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Internal\Json;

/**
 * The delegations block of a targets metadata file: the keys it introduces and
 * the ordered list of roles it delegates parts of its namespace to.
 */
final class Delegations
{
    /**
     * @param array<string, Key>  $keys
     * @param list<DelegatedRole> $roles
     */
    public function __construct(
        public readonly array $keys,
        public readonly array $roles,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = [];

        foreach (Json::object($data, 'keys') as $keyid => $key) {
            if (!is_array($key)) {
                continue;
            }
            /** @var array<string, mixed> $key */
            $keys[(string) $keyid] = Key::fromArray($key);
        }
        $roles = [];

        foreach (Json::list($data, 'roles') as $role) {
            if (!is_array($role)) {
                continue;
            }
            /** @var array<string, mixed> $role */
            $roles[] = DelegatedRole::fromArray($role);
        }

        return new self(keys: $keys, roles: $roles);
    }
}
