<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Exception\LengthOrHashMismatchException;
use K2gl\Tuf\Tests\Support\LocalFetcher;
use K2gl\Tuf\Tests\Support\Meta;
use K2gl\Tuf\Tests\Support\RepoBuilder;
use K2gl\Tuf\Tests\Support\SigningKey;
use K2gl\Tuf\Updater;
use LogicException;

use function K2gl\PHPUnitFluentAssertions\fact;

final class UpdaterTest extends \PHPUnit\Framework\TestCase
{
    private const META_URL = 'https://example.test/metadata';
    private const TARGET_URL = 'https://example.test/targets';
    private const TARGET_CONTENT = 'abc';

    public function testRefreshThenResolveAndDownloadTarget(): void
    {
        $repo = new RepoBuilder;
        $fetcher = $this->publish($repo);

        $updater = new Updater($repo->rootDoc(), self::META_URL, self::TARGET_URL, $fetcher);
        $updater->refresh();

        $info = $updater->getTargetInfo('trusted_root.json');
        fact($info)->notNull();
        fact($info->length)->is(3);

        fact($updater->downloadTarget($info))->is(self::TARGET_CONTENT);
    }

    public function testRefreshAppliesRootRotation(): void
    {
        $repo = new RepoBuilder;
        $rootV1 = $repo->rootDoc();

        // Root v2 rotates the timestamp key; only an updater that applies v2 can
        // then accept the timestamp signed by the new key.
        $repo->timestampKey = SigningKey::generate();
        $repo->rootVersion = 2;
        $rootV2 = $repo->rootDoc([$repo->rootKey]);

        $fetcher = $this->publish($repo);
        $fetcher->put(self::META_URL . '/2.root.json', $rootV2);

        $updater = new Updater($rootV1, self::META_URL, self::TARGET_URL, $fetcher);
        $updater->refresh();

        fact($updater->getTargetInfo('trusted_root.json'))->notNull();
    }

    public function testGetTrustedRootBytesReturnsLatestRootAfterRotation(): void
    {
        $repo = new RepoBuilder;
        $rootV1 = $repo->rootDoc();

        $repo->timestampKey = SigningKey::generate();
        $repo->rootVersion = 2;
        $rootV2 = $repo->rootDoc([$repo->rootKey]);

        $fetcher = $this->publish($repo);
        $fetcher->put(self::META_URL . '/2.root.json', $rootV2);

        $updater = new Updater($rootV1, self::META_URL, self::TARGET_URL, $fetcher);
        $updater->refresh();

        fact($updater->getTrustedRootBytes())->is($rootV2);
    }

    public function testGetTrustedRootBytesBeforeRefreshThrows(): void
    {
        // arrange
        $repo = new RepoBuilder;
        $updater = new Updater($repo->rootDoc(), self::META_URL, self::TARGET_URL, $this->publish($repo));

        // act + assert
        fact(static fn () => $updater->getTrustedRootBytes())->throws(LogicException::class);
    }

    public function testGetTargetInfoReturnsNullForUnknownTarget(): void
    {
        $repo = new RepoBuilder;
        $updater = new Updater($repo->rootDoc(), self::META_URL, self::TARGET_URL, $this->publish($repo));
        $updater->refresh();

        fact($updater->getTargetInfo('does/not/exist'))->null();
    }

    public function testDownloadRejectsTamperedTarget(): void
    {
        // arrange
        $repo = new RepoBuilder;
        $fetcher = $this->publish($repo);
        // Serve different bytes of the same length under the target's content path.
        $fetcher->put(self::TARGET_URL . '/' . Meta::sha256(self::TARGET_CONTENT) . '.trusted_root.json', 'xyz');

        $updater = new Updater($repo->rootDoc(), self::META_URL, self::TARGET_URL, $fetcher);
        $updater->refresh();
        $info = $updater->getTargetInfo('trusted_root.json');
        fact($info)->notNull();

        // act + assert
        fact(static fn () => $updater->downloadTarget($info))->throws(LengthOrHashMismatchException::class);
    }

    public function testQueryBeforeRefreshThrows(): void
    {
        // arrange
        $repo = new RepoBuilder;
        $updater = new Updater($repo->rootDoc(), self::META_URL, self::TARGET_URL, $this->publish($repo));

        // act + assert
        fact(static fn () => $updater->getTargetInfo('trusted_root.json'))->throws(LogicException::class);
    }

    /** Publish a consistent-snapshot repository for the builder into a fresh fetcher. */
    private function publish(RepoBuilder $repo): LocalFetcher
    {
        $fetcher = new LocalFetcher;
        $fetcher->put(self::META_URL . '/timestamp.json', $repo->timestampDoc());
        $fetcher->put(self::META_URL . '/' . $repo->snapshotVersion . '.snapshot.json', $repo->snapshotDoc());
        $fetcher->put(self::META_URL . '/' . $repo->targetsVersion . '.targets.json', $repo->targetsDoc());
        $fetcher->put(
            self::TARGET_URL . '/' . Meta::sha256(self::TARGET_CONTENT) . '.trusted_root.json',
            self::TARGET_CONTENT,
        );

        return $fetcher;
    }
}
