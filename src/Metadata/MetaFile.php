<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\LengthOrHashMismatchException;
use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Hashes;
use K2gl\Tuf\Internal\Json;

/**
 * A reference, inside timestamp or snapshot metadata, to another metadata file:
 * its version, and optionally the length and hashes the referenced file must
 * have. The version is always present; length and hashes are present only when
 * the repository chose to constrain them.
 */
final class MetaFile
{
    /** @param array<string, string> $hashes algorithm => lowercase hex digest */
    public function __construct(
        public readonly int $version,
        public readonly ?int $length = null,
        public readonly array $hashes = [],
    ) {
        if ($version < 1) {
            throw new RepositoryException('Meta version must be at least 1.');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            version: Json::int($data, 'version'),
            length: isset($data['length']) ? Json::int($data, 'length') : null,
            hashes: isset($data['hashes']) ? Hashes::fromArray(Json::object($data, 'hashes')) : [],
        );
    }

    /** Verify downloaded bytes against the recorded length and hashes (when present). */
    public function verify(string $bytes): void
    {
        if ($this->length !== null && \strlen($bytes) !== $this->length) {
            throw new LengthOrHashMismatchException(\sprintf(
                'Length mismatch: expected %d bytes, got %d.',
                $this->length,
                \strlen($bytes),
            ));
        }
        Hashes::verify($this->hashes, $bytes);
    }
}
