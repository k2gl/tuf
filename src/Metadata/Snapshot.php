<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Internal\Json;

/**
 * Snapshot metadata: the consistent view of the repository. It records the
 * version of every targets metadata file (top-level and delegated) that belongs
 * together, so a client cannot be served a mismatched mixture.
 */
final class Snapshot extends Signed
{
    /** @param array<string, MetaFile> $meta filename => referenced targets metadata */
    public function __construct(
        string $specVersion,
        int $version,
        \DateTimeImmutable $expires,
        public readonly array $meta,
    ) {
        parent::__construct('snapshot', $specVersion, $version, $expires);
    }

    /** @param array<string, mixed> $signed */
    public static function fromArray(array $signed): self
    {
        $meta = [];

        foreach (Json::object($signed, 'meta') as $filename => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            /** @var array<string, mixed> $entry */
            $meta[(string) $filename] = MetaFile::fromArray($entry);
        }
        self::requireType($signed, 'snapshot');

        return new self(
            specVersion: Json::string($signed, 'spec_version'),
            version: Json::int($signed, 'version'),
            expires: self::parseExpires($signed),
            meta: $meta,
        );
    }

    public function metaFor(string $filename): ?MetaFile
    {
        return $this->meta[$filename] ?? null;
    }
}
