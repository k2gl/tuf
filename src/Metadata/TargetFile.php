<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\LengthOrHashMismatchException;
use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Hashes;
use K2gl\Tuf\Internal\Json;

/**
 * A target listed in targets metadata: the path it is published under, the exact
 * length and hashes its content must have, and any opaque `custom` data the
 * repository attached (for Sigstore, this carries the trusted-root metadata).
 */
final class TargetFile
{
    /**
     * @param array<string, string> $hashes algorithm => lowercase hex digest
     * @param array<string, mixed>  $custom
     */
    public function __construct(
        public readonly string $path,
        public readonly int $length,
        public readonly array $hashes,
        public readonly array $custom = [],
    ) {
        if ($length < 0) {
            throw new RepositoryException('Target length cannot be negative.');
        }

        if ($hashes === []) {
            throw new RepositoryException('Target must record at least one hash.');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(string $path, array $data): self
    {
        /** @var array<string, mixed> $custom */
        $custom = isset($data['custom']) ? Json::object($data, 'custom') : [];

        return new self(
            path: $path,
            length: Json::int($data, 'length'),
            hashes: Hashes::fromArray(Json::object($data, 'hashes')),
            custom: $custom,
        );
    }

    /** Verify downloaded target bytes against the recorded length and hashes. */
    public function verifyLengthAndHashes(string $bytes): void
    {
        if (\strlen($bytes) !== $this->length) {
            throw new LengthOrHashMismatchException(\sprintf(
                'Target "%s" length mismatch: expected %d bytes, got %d.',
                $this->path,
                $this->length,
                \strlen($bytes),
            ));
        }
        Hashes::verify($this->hashes, $bytes);
    }
}
