<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Tests\Support\LocalFetcher;
use K2gl\Tuf\Updater;
use PHPUnit\Framework\TestCase;
use DateTimeImmutable;

use function K2gl\PHPUnitFluentAssertions\fact;

/**
 * End-to-end refresh against a real, vendored Sigstore public-good TUF snapshot
 * (tuf-repo-cdn.sigstore.dev, root v15): the client walks the root chain, then
 * timestamp -> snapshot -> targets, and downloads the `trusted_root.json` target
 * with its length and hash verified — all against genuine ECDSA-signed metadata.
 *
 * Offline: a {@see LocalFetcher} serves the captured files. The reference time is
 * pinned inside the snapshot's validity window, so the test stays deterministic
 * as the real metadata expires.
 */
final class SigstoreTufE2eTest extends TestCase
{
    private const METADATA_URL = 'https://tuf-repo-cdn.sigstore.dev';
    private const TARGETS_URL = 'https://tuf-repo-cdn.sigstore.dev/targets';
    private const TARGET_HASH = '6494e21ea73fa7ee769f85f57d5a3e6a08725eae1e38c755fc3517c9e6bc0b66';

    public function testRefreshesAndDownloadsTrustedRootFromRealSnapshot(): void
    {
        $dir = __DIR__ . '/fixtures/sigstore-tuf-real';

        $fetcher = new LocalFetcher([
            self::METADATA_URL . '/timestamp.json' => self::read($dir . '/timestamp.json'),
            self::METADATA_URL . '/165.snapshot.json' => self::read($dir . '/165.snapshot.json'),
            self::METADATA_URL . '/14.targets.json' => self::read($dir . '/14.targets.json'),
            self::TARGETS_URL . '/' . self::TARGET_HASH . '.trusted_root.json'
                => self::read($dir . '/targets/' . self::TARGET_HASH . '.trusted_root.json'),
        ]);

        $updater = new Updater(
            trustedRoot: self::read($dir . '/root.json'),
            metadataBaseUrl: self::METADATA_URL,
            targetBaseUrl: self::TARGETS_URL,
            fetcher: $fetcher,
            referenceTime: new DateTimeImmutable('2026-06-01T00:00:00Z'),
        );
        $updater->refresh();

        $info = $updater->getTargetInfo('trusted_root.json');
        fact($info)->notNull();

        // TUF verifies the target's length and hash; reaching here means the whole
        // real metadata chain checked out.
        $trustedRoot = $updater->downloadTarget($info);
        $decoded = json_decode($trustedRoot, true);
        fact($decoded)->isArray();
        /** @var array<string, mixed> $decoded */
        fact($decoded['mediaType'] ?? null)->is('application/vnd.dev.sigstore.trustedroot+json;version=0.1');
        fact(is_array($decoded['certificateAuthorities'] ?? null))->true();
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        fact($contents)->isString();

        return $contents;
    }
}
