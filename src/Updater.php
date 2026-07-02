<?php

declare(strict_types=1);

namespace K2gl\Tuf;

use K2gl\Tuf\Exception\DownloadException;
use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Metadata\DelegatedRole;
use K2gl\Tuf\Metadata\TargetFile;
use K2gl\Tuf\Metadata\Targets;
use DateTimeImmutable;
use LogicException;

/**
 * The TUF client: it refreshes the top-level metadata from a repository in the
 * specification's order (root, timestamp, snapshot, targets), then answers
 * questions about and downloads individual targets, with every byte verified by
 * the trusted metadata.
 *
 * It owns no trust logic itself — that lives in {@see TrustedMetadataSet} — and
 * touches the network only through the injected {@see Fetcher}, so it can be
 * pointed at a real repository, a local mirror, or a test double unchanged.
 *
 * Usage: construct with the embedded trusted root and the repository URLs, call
 * {@see refresh()} once, then {@see getTargetInfo()} / {@see downloadTarget()}.
 */
final class Updater
{
    private const MAX_ROOT_ROTATIONS = 32;
    private const MAX_DELEGATIONS = 32;
    private const ROOT_MAX_LENGTH = 512_000;
    private const TIMESTAMP_MAX_LENGTH = 16_384;
    private const SNAPSHOT_MAX_LENGTH = 4_000_000;
    private const TARGETS_MAX_LENGTH = 8_000_000;

    private readonly string $metadataBaseUrl;
    private readonly string $targetBaseUrl;
    private ?TrustedMetadataSet $trusted = null;

    public function __construct(
        private readonly string $trustedRoot,
        string $metadataBaseUrl,
        string $targetBaseUrl,
        private readonly Fetcher $fetcher,
        private readonly ?DateTimeImmutable $referenceTime = null,
    ) {
        $this->metadataBaseUrl = rtrim($metadataBaseUrl, '/');
        $this->targetBaseUrl = rtrim($targetBaseUrl, '/');
    }

    /**
     * Refresh the trusted top-level metadata. Performs the full client workflow
     * and leaves the updater ready to answer target queries. Throws on any
     * verification failure.
     */
    public function refresh(): void
    {
        $set = new TrustedMetadataSet($this->trustedRoot, $this->referenceTime);
        $this->loadRoot($set);
        $this->loadTimestamp($set);
        $this->loadSnapshot($set);
        $this->loadTopTargets($set);
        $this->trusted = $set;
    }

    /**
     * The raw bytes of the current trusted root (after any rotations performed
     * during {@see refresh()}), for the caller to persist locally so the next
     * refresh can start from the latest known root instead of an old embedded
     * one (5.3.11). Call {@see refresh()} first.
     */
    public function getTrustedRootBytes(): string
    {
        return $this->requireRefreshed()->rootBytes();
    }

    /**
     * The verified metadata for a target path, or null if no trusted targets
     * role lists it. Walks delegations depth-first, fetching delegated targets
     * metadata as needed. Call {@see refresh()} first.
     */
    public function getTargetInfo(string $targetPath): ?TargetFile
    {
        $set = $this->requireRefreshed();
        $visited = [];

        return $this->search($set, 'targets', 'root', $targetPath, $visited);
    }

    /**
     * Download a target and verify its length and hashes against the trusted
     * metadata before returning its bytes. Call {@see refresh()} first.
     */
    public function downloadTarget(TargetFile $info): string
    {
        $set = $this->requireRefreshed();
        $name = $set->root()->consistentSnapshot ? $this->hashPrefixedPath($info) : $info->path;
        $bytes = $this->fetcher->fetch($this->targetBaseUrl . '/' . $name, $info->length);
        $info->verifyLengthAndHashes($bytes);

        return $bytes;
    }

    private function loadRoot(TrustedMetadataSet $set): void
    {
        $current = $set->root()->version;

        for ($next = $current + 1; $next <= $current + self::MAX_ROOT_ROTATIONS; ++$next) {
            try {
                $bytes = $this->fetcher->fetch($this->metadataUrl($next . '.root.json'), self::ROOT_MAX_LENGTH);
            } catch (DownloadException) {
                return; // no further root version available
            }
            $set->updateRoot($bytes);
        }
    }

    private function loadTimestamp(TrustedMetadataSet $set): void
    {
        $bytes = $this->fetcher->fetch($this->metadataUrl('timestamp.json'), self::TIMESTAMP_MAX_LENGTH);
        $set->updateTimestamp($bytes);
    }

    private function loadSnapshot(TrustedMetadataSet $set): void
    {
        $timestamp = $set->timestamp() ?? throw new RepositoryException('Timestamp was not loaded.');
        $version = $timestamp->snapshotMeta->version;
        $name = $set->root()->consistentSnapshot ? $version . '.snapshot.json' : 'snapshot.json';
        $bytes = $this->fetcher->fetch($this->metadataUrl($name), self::SNAPSHOT_MAX_LENGTH);
        $set->updateSnapshot($bytes);
    }

    private function loadTopTargets(TrustedMetadataSet $set): void
    {
        $this->loadDelegated($set, 'targets', 'root');
    }

    private function loadDelegated(TrustedMetadataSet $set, string $role, string $delegator): Targets
    {
        $existing = $set->targets($role);

        if ($existing !== null) {
            return $existing;
        }
        $snapshot = $set->snapshot() ?? throw new RepositoryException('Snapshot was not loaded.');
        $meta = $snapshot->metaFor($role . '.json');

        if ($meta === null) {
            throw new RepositoryException(\sprintf('Snapshot does not list targets metadata "%s".', $role));
        }
        $name = $set->root()->consistentSnapshot ? $meta->version . '.' . $role . '.json' : $role . '.json';
        $bytes = $this->fetcher->fetch($this->metadataUrl($name), self::TARGETS_MAX_LENGTH);

        return $set->updateDelegatedTargets($bytes, $role, $delegator);
    }

    /**
     * Pre-order depth-first search through the delegation graph, honouring
     * terminating delegations and a visit cap.
     *
     * @param array<string, true> $visited
     */
    private function search(
        TrustedMetadataSet $set,
        string $role,
        string $delegator,
        string $targetPath,
        array &$visited,
    ): ?TargetFile {
        if (isset($visited[$role])) {
            return null;
        }

        if (\count($visited) >= self::MAX_DELEGATIONS) {
            throw new RepositoryException('Maximum number of delegations traversed.');
        }
        $visited[$role] = true;
        $targets = $this->loadDelegated($set, $role, $delegator);
        $found = $targets->target($targetPath);

        if ($found !== null) {
            return $found;
        }

        if ($targets->delegations === null) {
            return null;
        }

        foreach ($targets->delegations->roles as $delegation) {
            if (! self::pathMatches($delegation, $targetPath)) {
                continue;
            }
            $result = $this->search($set, $delegation->name, $role, $targetPath, $visited);

            if ($result !== null) {
                return $result;
            }

            if ($delegation->terminating) {
                return null;
            }
        }

        return null;
    }

    private static function pathMatches(DelegatedRole $delegation, string $targetPath): bool
    {
        if ($delegation->pathHashPrefixes !== []) {
            $hash = hash('sha256', $targetPath);

            foreach ($delegation->pathHashPrefixes as $prefix) {
                if (str_starts_with($hash, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($delegation->paths as $pattern) {
            if ($pattern === $targetPath || fnmatch($pattern, $targetPath)) {
                return true;
            }
        }

        return false;
    }

    private function hashPrefixedPath(TargetFile $info): string
    {
        $hashes = $info->hashes;
        $hash = $hashes['sha256'] ?? reset($hashes);

        if ($hash === false) {
            throw new RepositoryException('Target has no hash for a consistent-snapshot path.');
        }
        $slash = strrpos($info->path, '/');

        if ($slash === false) {
            return $hash . '.' . $info->path;
        }

        return substr($info->path, 0, $slash + 1) . $hash . '.' . substr($info->path, $slash + 1);
    }

    private function metadataUrl(string $name): string
    {
        return $this->metadataBaseUrl . '/' . $name;
    }

    private function requireRefreshed(): TrustedMetadataSet
    {
        return $this->trusted ?? throw new LogicException('Call refresh() before querying targets.');
    }
}
