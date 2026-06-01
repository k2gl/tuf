<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use function K2gl\PHPUnitFluentAssertions\fact;

use K2gl\Tuf\Exception\BadVersionException;
use K2gl\Tuf\Exception\ExpiredMetadataException;
use K2gl\Tuf\Exception\LengthOrHashMismatchException;
use K2gl\Tuf\Exception\UnsignedMetadataException;
use K2gl\Tuf\Tests\Support\Meta;
use K2gl\Tuf\Tests\Support\RepoBuilder;
use K2gl\Tuf\Tests\Support\SigningKey;
use K2gl\Tuf\TrustedMetadataSet;
use PHPUnit\Framework\TestCase;

final class TrustedMetadataSetTest extends TestCase
{
    public function testHappyPathLoadsAllRoles(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());
        $set->updateTimestamp($repo->timestampDoc());
        $set->updateSnapshot($repo->snapshotDoc());
        $targets = $set->updateTargets($repo->targetsDoc());

        fact($targets->target('trusted_root.json'))->notNull();
        fact($set->targets())->is($targets);
    }

    public function testUpdateRootAcceptsNextVersion(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $repo->rootVersion = 2;
        $root = $set->updateRoot($repo->rootDoc());

        fact($root->version)->is(2);
        fact($set->root()->version)->is(2);
    }

    public function testUpdateRootRejectsNonConsecutiveVersion(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $repo->rootVersion = 3;

        $this->expectException(BadVersionException::class);
        $set->updateRoot($repo->rootDoc());
    }

    public function testUpdateRootRequiresBothOldAndNewKeys(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $oldRootKey = $repo->rootKey;
        $repo->rootKey = SigningKey::generate(); // rotate the root key
        $repo->rootVersion = 2;

        // Signed only by the new key: the old root's threshold is not met.
        try {
            $set->updateRoot($repo->rootDoc([$repo->rootKey]));
            self::fail('Expected UnsignedMetadataException for missing old-key signature.');
        } catch (UnsignedMetadataException) {
            $this->addToAssertionCount(1);
        }

        // Signed only by the old key: the new root's threshold is not met.
        try {
            $set->updateRoot($repo->rootDoc([$oldRootKey]));
            self::fail('Expected UnsignedMetadataException for missing new-key signature.');
        } catch (UnsignedMetadataException) {
            $this->addToAssertionCount(1);
        }

        // Signed by both: accepted.
        $root = $set->updateRoot($repo->rootDoc([$oldRootKey, $repo->rootKey]));
        fact($root->version)->is(2);
    }

    public function testCannotUpdateRootAfterTimestamp(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());
        $set->updateTimestamp($repo->timestampDoc());

        $repo->rootVersion = 2;

        $this->expectException(\LogicException::class);
        $set->updateRoot($repo->rootDoc());
    }

    public function testExpiredRootRejectedAtTimestampUpdate(): void
    {
        $repo = new RepoBuilder();
        $repo->rootExpires = '2000-01-01T00:00:00Z';
        $set = new TrustedMetadataSet($repo->rootDoc()); // initial root: expiry not checked

        $this->expectException(ExpiredMetadataException::class);
        $set->updateTimestamp($repo->timestampDoc());
    }

    public function testTimestampVersionRollbackRejected(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $repo->timestampVersion = 2;
        $set->updateTimestamp($repo->timestampDoc());

        $repo->timestampVersion = 1;

        $this->expectException(BadVersionException::class);
        $set->updateTimestamp($repo->timestampDoc());
    }

    public function testTimestampSnapshotRollbackRejected(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $repo->snapshotVersion = 2;
        $repo->timestampVersion = 1;
        $set->updateTimestamp($repo->timestampDoc());

        $repo->snapshotVersion = 1;
        $repo->timestampVersion = 2;

        $this->expectException(BadVersionException::class);
        $set->updateTimestamp($repo->timestampDoc());
    }

    public function testSnapshotLengthOrHashMismatchRejected(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());
        $set->updateTimestamp($repo->timestampDoc());

        // Different bytes than the timestamp committed to.
        $this->expectException(LengthOrHashMismatchException::class);
        $set->updateSnapshot($repo->snapshotDoc() . ' ');
    }

    public function testSnapshotVersionMustMatchTimestamp(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $snapshot = $repo->snapshotDoc();
        $timestamp = Meta::document([
            '_type' => 'timestamp',
            'spec_version' => '1.0.0',
            'version' => 1,
            'expires' => '2099-01-01T00:00:00Z',
            'meta' => [
                'snapshot.json' => [
                    'version' => 99, // disagrees with the snapshot's own version (1)
                    'length' => \strlen($snapshot),
                    'hashes' => ['sha256' => Meta::sha256($snapshot)],
                ],
            ],
        ], $repo->timestampKey);
        $set->updateTimestamp($timestamp);

        $this->expectException(BadVersionException::class);
        $set->updateSnapshot($snapshot);
    }

    public function testSnapshotTargetsRollbackRejected(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        // Timestamp constrains only the snapshot version (no length/hashes), so
        // two different snapshots of that version can both be offered.
        $set->updateTimestamp(Meta::document([
            '_type' => 'timestamp',
            'spec_version' => '1.0.0',
            'version' => 1,
            'expires' => '2099-01-01T00:00:00Z',
            'meta' => ['snapshot.json' => ['version' => 5]],
        ], $repo->timestampKey));

        $set->updateSnapshot($this->snapshotWithTargetsVersion($repo, 5, 2)); // trusted: targets v2

        // A second snapshot of the same version that rolls targets back to v1.
        $this->expectException(BadVersionException::class);
        $set->updateSnapshot($this->snapshotWithTargetsVersion($repo, 5, 1));
    }

    private function snapshotWithTargetsVersion(RepoBuilder $repo, int $snapshotVersion, int $targetsVersion): string
    {
        return Meta::document([
            '_type' => 'snapshot',
            'spec_version' => '1.0.0',
            'version' => $snapshotVersion,
            'expires' => '2099-01-01T00:00:00Z',
            'meta' => ['targets.json' => ['version' => $targetsVersion]],
        ], $repo->snapshotKey);
    }

    public function testExpiredTimestampRejectedAtSnapshotUpdate(): void
    {
        $repo = new RepoBuilder();
        $repo->timestampExpires = '2000-01-01T00:00:00Z';
        $set = new TrustedMetadataSet($repo->rootDoc());
        $set->updateTimestamp($repo->timestampDoc()); // loading an expired timestamp is allowed

        $this->expectException(ExpiredMetadataException::class);
        $set->updateSnapshot($repo->snapshotDoc());
    }

    public function testExpiredSnapshotRejectedAtTargetsUpdate(): void
    {
        $repo = new RepoBuilder();
        $repo->snapshotExpires = '2000-01-01T00:00:00Z';
        $set = new TrustedMetadataSet($repo->rootDoc());
        $set->updateTimestamp($repo->timestampDoc());
        $set->updateSnapshot($repo->snapshotDoc()); // loading an expired snapshot is allowed

        $this->expectException(ExpiredMetadataException::class);
        $set->updateTargets($repo->targetsDoc());
    }

    public function testTargetsVersionMustMatchSnapshot(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $targets = $repo->targetsDoc();
        $snapshot = Meta::document([
            '_type' => 'snapshot',
            'spec_version' => '1.0.0',
            'version' => 1,
            'expires' => '2099-01-01T00:00:00Z',
            'meta' => [
                'targets.json' => [
                    'version' => 99, // disagrees with the targets' own version (1)
                    'length' => \strlen($targets),
                    'hashes' => ['sha256' => Meta::sha256($targets)],
                ],
            ],
        ], $repo->snapshotKey);
        $timestamp = Meta::document([
            '_type' => 'timestamp',
            'spec_version' => '1.0.0',
            'version' => 1,
            'expires' => '2099-01-01T00:00:00Z',
            'meta' => [
                'snapshot.json' => [
                    'version' => 1,
                    'length' => \strlen($snapshot),
                    'hashes' => ['sha256' => Meta::sha256($snapshot)],
                ],
            ],
        ], $repo->timestampKey);

        $set->updateTimestamp($timestamp);
        $set->updateSnapshot($snapshot);

        $this->expectException(BadVersionException::class);
        $set->updateTargets($targets);
    }

    public function testKeyRotationInvalidatesOldTimestampKey(): void
    {
        $repo = new RepoBuilder();
        $set = new TrustedMetadataSet($repo->rootDoc());

        $oldTimestampKey = $repo->timestampKey;
        $repo->timestampKey = SigningKey::generate(); // root v2 rotates the timestamp key
        $repo->rootVersion = 2;
        $set->updateRoot($repo->rootDoc([$repo->rootKey]));

        // The old timestamp key is no longer trusted.
        try {
            $set->updateTimestamp($repo->timestampDoc($oldTimestampKey));
            self::fail('Expected UnsignedMetadataException for rotated-out timestamp key.');
        } catch (UnsignedMetadataException) {
            $this->addToAssertionCount(1);
        }

        // The new timestamp key works.
        $timestamp = $set->updateTimestamp($repo->timestampDoc());
        fact($timestamp->version)->is(1);
    }
}
