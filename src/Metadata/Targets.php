<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Json;

use function sprintf;

/**
 * Targets metadata: what the repository actually vouches for. It lists each
 * target file with its length and hashes, and may delegate parts of its
 * namespace to further roles.
 */
final class Targets extends Signed
{
    /** @param array<string, TargetFile> $targets path => target */
    public function __construct(
        string $specVersion,
        int $version,
        \DateTimeImmutable $expires,
        public readonly array $targets,
        public readonly ?Delegations $delegations = null,
    ) {
        parent::__construct('targets', $specVersion, $version, $expires);
    }

    /** @param array<string, mixed> $signed */
    public static function fromArray(array $signed): self
    {
        self::requireType($signed, 'targets');
        $targets = [];

        foreach (Json::object($signed, 'targets') as $path => $target) {
            if (!is_array($target)) {
                throw new RepositoryException('Targets "targets" must map paths to target objects.');
            }
            /** @var array<string, mixed> $target */
            $targets[(string) $path] = TargetFile::fromArray((string) $path, $target);
        }

        return new self(
            specVersion: Json::string($signed, 'spec_version'),
            version: Json::int($signed, 'version'),
            expires: self::parseExpires($signed),
            targets: $targets,
            delegations: isset($signed['delegations']) ? Delegations::fromArray(Json::object($signed, 'delegations')) : null,
        );
    }

    public function target(string $path): ?TargetFile
    {
        return $this->targets[$path] ?? null;
    }

    /**
     * Verify that the given metadata is signed by the threshold of keys this
     * targets file delegates to the named role.
     */
    public function verifyDelegate(string $roleName, Metadata $metadata): void
    {
        if ($this->delegations === null) {
            throw new RepositoryException(sprintf('No delegations to verify role "%s".', $roleName));
        }

        foreach ($this->delegations->roles as $role) {
            if ($role->name === $roleName) {
                $metadata->verifySignedBy($roleName, $this->delegations->keys, $role);

                return;
            }
        }

        throw new RepositoryException(sprintf('No delegated role "%s".', $roleName));
    }
}
