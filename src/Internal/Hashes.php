<?php

declare(strict_types=1);

namespace K2gl\Tuf\Internal;

use K2gl\Tuf\Exception\LengthOrHashMismatchException;
use K2gl\Tuf\Exception\RepositoryException;

use function sprintf;

/**
 * Parses and verifies the `hashes` maps (algorithm => hex digest) that TUF
 * metadata records for the files it refers to.
 */
final class Hashes
{
    /** Algorithms this version can verify, mapped to their PHP {@see hash()} name. */
    private const SUPPORTED = [
        'sha256' => 'sha256',
        'sha512' => 'sha512',
    ];

    /**
     * @param  array<string, mixed> $data
     * @return array<string, string>
     */
    public static function fromArray(array $data): array
    {
        $hashes = [];

        foreach ($data as $algorithm => $digest) {
            if (!is_string($digest)) {
                throw new RepositoryException(sprintf('Hash digest for "%s" must be a string.', $algorithm));
            }
            $hashes[$algorithm] = $digest;
        }

        return $hashes;
    }

    /**
     * Verify the bytes against every recorded hash. Each listed algorithm must
     * be one this version supports and its digest must match; an unsupported
     * algorithm is rejected rather than skipped (fail-closed). An empty map
     * imposes no hash constraint.
     *
     * @param array<string, string> $hashes
     */
    public static function verify(array $hashes, string $bytes): void
    {
        foreach ($hashes as $algorithm => $expected) {
            $php = self::SUPPORTED[$algorithm] ?? throw new LengthOrHashMismatchException(
                sprintf('Unsupported hash algorithm "%s".', $algorithm),
            );
            $actual = hash($php, $bytes);

            if (!hash_equals($actual, strtolower($expected))) {
                throw new LengthOrHashMismatchException(sprintf('Hash mismatch for "%s".', $algorithm));
            }
        }
    }
}
