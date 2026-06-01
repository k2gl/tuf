<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\Json;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The payload of a TUF metadata file: the `signed` object that signatures are
 * computed over. Every role shares the version and expiry fields modelled here;
 * each concrete subclass adds the fields specific to its role.
 */
abstract class Signed
{
    final public const SPEC_FAMILY = '1';

    public function __construct(
        public readonly string $type,
        public readonly string $specVersion,
        public readonly int $version,
        public readonly DateTimeImmutable $expires,
    ) {
        if ($version < 1) {
            throw new RepositoryException('Metadata version must be at least 1.');
        }
    }

    /**
     * Build the right concrete payload from a decoded `signed` object, dispatching
     * on its `_type`.
     *
     * @param array<string, mixed> $signed
     */
    public static function fromArray(array $signed): self
    {
        $type = Json::string($signed, '_type');

        return match ($type) {
            'root' => Root::fromArray($signed),
            'timestamp' => Timestamp::fromArray($signed),
            'snapshot' => Snapshot::fromArray($signed),
            'targets' => Targets::fromArray($signed),
            default => throw new RepositoryException(\sprintf('Unknown metadata type "%s".', $type)),
        };
    }

    /** Whether the metadata is expired relative to the given reference time. */
    public function isExpired(DateTimeImmutable $reference): bool
    {
        return $reference >= $this->expires;
    }

    /** @param array<string, mixed> $signed */
    protected static function requireType(array $signed, string $expected): string
    {
        $type = Json::string($signed, '_type');

        if ($type !== $expected) {
            throw new RepositoryException(\sprintf('Expected "%s" metadata, got "%s".', $expected, $type));
        }

        return $type;
    }

    /** @param array<string, mixed> $signed */
    protected static function parseExpires(array $signed): DateTimeImmutable
    {
        $raw = Json::string($signed, 'expires');
        $expires = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $raw, new DateTimeZone('UTC'));

        if ($expires === false) {
            throw new RepositoryException(\sprintf('Invalid "expires" timestamp "%s".', $raw));
        }

        return $expires;
    }
}
