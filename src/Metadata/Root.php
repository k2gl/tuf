<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Json;
use DateTimeImmutable;

/**
 * Root metadata: the trust anchor. It lists every key the repository uses and,
 * for each of the four top-level roles, which of those keys may sign for it and
 * how many must. Root is itself signed by its own role's keys.
 */
final class Root extends Signed
{
    private const TOP_LEVEL_ROLES = ['root', 'timestamp', 'snapshot', 'targets'];

    /**
     * @param array<string, Key>  $keys
     * @param array<string, Role> $roles
     */
    public function __construct(
        string $specVersion,
        int $version,
        DateTimeImmutable $expires,
        public readonly bool $consistentSnapshot,
        public readonly array $keys,
        public readonly array $roles,
    ) {
        parent::__construct('root', $specVersion, $version, $expires);

        foreach (self::TOP_LEVEL_ROLES as $name) {
            if (! isset($this->roles[$name])) {
                throw new RepositoryException(\sprintf('Root metadata is missing the "%s" role.', $name));
            }
        }
    }

    /** @param array<string, mixed> $signed */
    public static function fromArray(array $signed): self
    {
        self::requireType($signed, 'root');
        $keys = [];

        foreach (Json::object($signed, 'keys') as $keyid => $key) {
            if (! is_array($key)) {
                throw new RepositoryException('Root "keys" must map key ids to key objects.');
            }
            /** @var array<string, mixed> $key */
            $keys[(string) $keyid] = Key::fromArray($key);
        }
        $roles = [];

        foreach (Json::object($signed, 'roles') as $name => $role) {
            if (! is_array($role)) {
                throw new RepositoryException('Root "roles" must map role names to role objects.');
            }
            /** @var array<string, mixed> $role */
            $roles[(string) $name] = Role::fromArray($role);
        }

        return new self(
            specVersion: Json::string($signed, 'spec_version'),
            version: Json::int($signed, 'version'),
            expires: self::parseExpires($signed),
            consistentSnapshot: isset($signed['consistent_snapshot']) ? Json::bool($signed, 'consistent_snapshot') : false,
            keys: $keys,
            roles: $roles,
        );
    }

    public function role(string $name): Role
    {
        return $this->roles[$name] ?? throw new RepositoryException(\sprintf('No such role "%s".', $name));
    }

    /**
     * Verify that the given metadata is signed by the threshold of keys this root
     * trusts for the named top-level role.
     */
    public function verifyDelegate(string $roleName, Metadata $metadata): void
    {
        $metadata->verifySignedBy($roleName, $this->keys, $this->role($roleName));
    }
}
