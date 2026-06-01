<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Internal\Json;
use DateTimeImmutable;

/**
 * Timestamp metadata: the frequently-resigned pointer to the current snapshot.
 * It records the version (and optionally length and hashes) of the snapshot
 * metadata the client should be using.
 */
final class Timestamp extends Signed
{
    public function __construct(
        string $specVersion,
        int $version,
        DateTimeImmutable $expires,
        public readonly MetaFile $snapshotMeta,
    ) {
        parent::__construct('timestamp', $specVersion, $version, $expires);
    }

    /** @param array<string, mixed> $signed */
    public static function fromArray(array $signed): self
    {
        self::requireType($signed, 'timestamp');
        $meta = Json::object($signed, 'meta');

        return new self(
            specVersion: Json::string($signed, 'spec_version'),
            version: Json::int($signed, 'version'),
            expires: self::parseExpires($signed),
            snapshotMeta: MetaFile::fromArray(Json::object($meta, 'snapshot.json')),
        );
    }
}
