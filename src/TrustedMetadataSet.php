<?php

declare(strict_types=1);

namespace K2gl\Tuf;

use K2gl\Tuf\Exception\BadVersionException;
use K2gl\Tuf\Exception\ExpiredMetadataException;
use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Metadata\Metadata;
use K2gl\Tuf\Metadata\Root;
use K2gl\Tuf\Metadata\Snapshot;
use K2gl\Tuf\Metadata\Targets;
use K2gl\Tuf\Metadata\Timestamp;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;

/**
 * The trusted set of TUF metadata, and the rules for growing it. This is the
 * security core: it holds the currently trusted root, timestamp, snapshot and
 * targets, and every `update*` method applies the TUF client workflow checks
 * (threshold signatures, rollback/version, expiry, length/hashes) in the exact
 * order the specification mandates, throwing on the first violation.
 *
 * It performs no I/O. A caller (typically {@see Updater}) is responsible for
 * fetching bytes and feeding them here in the right order: root updates first
 * (zero or more), then timestamp, then snapshot, then targets and any
 * delegated targets. Updating root after timestamp is rejected, which is what
 * structurally recovers from timestamp/snapshot key rotation: a refresh always
 * re-fetches timestamp and snapshot after the root chain settles.
 *
 * Expiry is checked against a fixed reference time captured at construction, so
 * a long refresh cannot be tricked by the clock advancing mid-flight. Following
 * the specification, expiry of a freshly loaded timestamp/snapshot is verified
 * lazily (when the next role consumes it), so possibly-expired cached metadata
 * can still bootstrap an update.
 *
 * @see https://theupdateframework.github.io/specification/latest/#detailed-client-workflow
 */
final class TrustedMetadataSet
{
    private Root $root;
    private ?Timestamp $timestamp = null;
    private ?Snapshot $snapshot = null;

    /** @var array<string, Targets> delegated role name => trusted targets ("targets" is the top role) */
    private array $targets = [];

    private readonly DateTimeImmutable $referenceTime;

    public function __construct(string $rootBytes, ?DateTimeImmutable $referenceTime = null)
    {
        $this->referenceTime = $referenceTime ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->loadTrustedRoot($rootBytes);
    }

    public function root(): Root
    {
        return $this->root;
    }

    public function timestamp(): ?Timestamp
    {
        return $this->timestamp;
    }

    public function snapshot(): ?Snapshot
    {
        return $this->snapshot;
    }

    public function targets(string $role = 'targets'): ?Targets
    {
        return $this->targets[$role] ?? null;
    }

    /**
     * Verify and adopt a new root, one version newer than the trusted one. Must
     * be called before any timestamp update. The new root must be signed by the
     * threshold of BOTH the current and the new root's keys (5.3.4), and its
     * version must be exactly one greater (5.3.5).
     */
    public function updateRoot(string $rootBytes): Root
    {
        if ($this->timestamp !== null) {
            throw new LogicException('Cannot update root after timestamp has been loaded.');
        }
        $metadata = Metadata::fromJson($rootBytes);
        $newRoot = $metadata->signed;

        if (! $newRoot instanceof Root) {
            throw new RepositoryException('Expected root metadata.');
        }

        // Signed by a threshold of the currently trusted root's keys.
        $this->root->verifyDelegate('root', $metadata);

        if ($newRoot->version !== $this->root->version + 1) {
            throw new BadVersionException(\sprintf(
                'Expected root version %d, got %d.',
                $this->root->version + 1,
                $newRoot->version,
            ));
        }

        // Signed by a threshold of its own (new) keys.
        $newRoot->verifyDelegate('root', $metadata);
        $this->root = $newRoot;

        return $newRoot;
    }

    /**
     * Verify and adopt a new timestamp. The trusted root must not be expired
     * (5.3.10), the timestamp must be signed by the root's timestamp role, and
     * neither its own version nor the snapshot version it points at may roll
     * back (5.4.3.1, 5.4.3.2).
     */
    public function updateTimestamp(string $timestampBytes): Timestamp
    {
        if ($this->snapshot !== null) {
            throw new LogicException('Cannot update timestamp after snapshot has been loaded.');
        }

        if ($this->root->isExpired($this->referenceTime)) {
            throw new ExpiredMetadataException('Trusted root metadata is expired.');
        }
        $metadata = Metadata::fromJson($timestampBytes);
        $newTimestamp = $metadata->signed;

        if (! $newTimestamp instanceof Timestamp) {
            throw new RepositoryException('Expected timestamp metadata.');
        }
        $this->root->verifyDelegate('timestamp', $metadata);

        if ($this->timestamp !== null) {
            if ($newTimestamp->version < $this->timestamp->version) {
                throw new BadVersionException('Timestamp version rolled back.');
            }

            if ($newTimestamp->snapshotMeta->version < $this->timestamp->snapshotMeta->version) {
                throw new BadVersionException('Timestamp points at an older snapshot.');
            }
        }
        $this->timestamp = $newTimestamp;

        return $newTimestamp;
    }

    /**
     * Verify and adopt a new snapshot. The trusted timestamp must not be expired
     * (5.5, lazy timestamp freeze check); the bytes must match the length and
     * hashes the timestamp recorded (unless loaded from already-trusted local
     * storage); the snapshot must be signed by the root's snapshot role, match
     * the version the timestamp points at, and not roll back or drop any targets
     * metadata listed in the trusted snapshot (5.5.5).
     */
    public function updateSnapshot(string $snapshotBytes, bool $trusted = false): Snapshot
    {
        $timestamp = $this->timestamp;

        if ($timestamp === null) {
            throw new LogicException('Cannot update snapshot before timestamp.');
        }
        $this->checkFinalTimestamp();
        $snapshotMeta = $timestamp->snapshotMeta;

        if (! $trusted) {
            $snapshotMeta->verify($snapshotBytes);
        }
        $metadata = Metadata::fromJson($snapshotBytes);
        $newSnapshot = $metadata->signed;

        if (! $newSnapshot instanceof Snapshot) {
            throw new RepositoryException('Expected snapshot metadata.');
        }
        $this->root->verifyDelegate('snapshot', $metadata);

        if ($newSnapshot->version !== $snapshotMeta->version) {
            throw new BadVersionException(\sprintf(
                'Expected snapshot version %d, got %d.',
                $snapshotMeta->version,
                $newSnapshot->version,
            ));
        }

        if ($this->snapshot !== null) {
            foreach ($this->snapshot->meta as $filename => $trustedMeta) {
                $candidate = $newSnapshot->metaFor($filename);

                if ($candidate === null) {
                    throw new BadVersionException(\sprintf('Snapshot drops targets metadata "%s".', $filename));
                }

                if ($candidate->version < $trustedMeta->version) {
                    throw new BadVersionException(\sprintf('Targets metadata "%s" rolled back.', $filename));
                }
            }
        }
        $this->snapshot = $newSnapshot;

        return $newSnapshot;
    }

    /** Verify and adopt the top-level targets metadata. */
    public function updateTargets(string $targetsBytes): Targets
    {
        return $this->updateDelegatedTargets($targetsBytes, 'targets', 'root');
    }

    /**
     * Verify and adopt a (delegated) targets metadata file. The trusted snapshot
     * must not be expired and must agree with the timestamp (lazy snapshot freeze
     * check); the bytes must match the length and hashes the snapshot recorded;
     * the metadata must be signed by its delegator's role and match the version
     * the snapshot points at (5.6).
     */
    public function updateDelegatedTargets(string $targetsBytes, string $roleName, string $delegatorName): Targets
    {
        $snapshot = $this->snapshot;

        if ($snapshot === null) {
            throw new LogicException('Cannot update targets before snapshot.');
        }
        $this->checkFinalSnapshot();
        $delegator = $this->delegator($delegatorName);
        $meta = $snapshot->metaFor($roleName . '.json');

        if ($meta === null) {
            throw new RepositoryException(\sprintf('Snapshot does not list targets metadata "%s".', $roleName));
        }
        $meta->verify($targetsBytes);
        $metadata = Metadata::fromJson($targetsBytes);
        $newTargets = $metadata->signed;

        if (! $newTargets instanceof Targets) {
            throw new RepositoryException('Expected targets metadata.');
        }
        $delegator->verifyDelegate($roleName, $metadata);

        if ($newTargets->version !== $meta->version) {
            throw new BadVersionException(\sprintf(
                'Expected targets version %d for "%s", got %d.',
                $meta->version,
                $roleName,
                $newTargets->version,
            ));
        }
        $this->targets[$roleName] = $newTargets;

        return $newTargets;
    }

    private function loadTrustedRoot(string $rootBytes): void
    {
        $metadata = Metadata::fromJson($rootBytes);
        $root = $metadata->signed;

        if (! $root instanceof Root) {
            throw new RepositoryException('Expected root metadata.');
        }

        // The initial root is the trust anchor: require it to be self-consistent
        // (signed by its own keys). Its expiry is not checked here, by design.
        $root->verifyDelegate('root', $metadata);
        $this->root = $root;
    }

    private function delegator(string $delegatorName): Root|Targets
    {
        if ($delegatorName === 'root') {
            return $this->root;
        }

        return $this->targets[$delegatorName]
            ?? throw new LogicException(\sprintf('Delegating role "%s" has not been loaded.', $delegatorName));
    }

    private function checkFinalTimestamp(): void
    {
        if ($this->timestamp !== null && $this->timestamp->isExpired($this->referenceTime)) {
            throw new ExpiredMetadataException('Trusted timestamp metadata is expired.');
        }
    }

    private function checkFinalSnapshot(): void
    {
        if ($this->snapshot === null || $this->timestamp === null) {
            return;
        }

        if ($this->snapshot->isExpired($this->referenceTime)) {
            throw new ExpiredMetadataException('Trusted snapshot metadata is expired.');
        }

        if ($this->snapshot->version !== $this->timestamp->snapshotMeta->version) {
            throw new BadVersionException('Trusted snapshot version no longer matches the timestamp.');
        }
    }
}
