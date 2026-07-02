# k2gl/tuf

[![CI](https://img.shields.io/github/actions/workflow/status/k2gl/tuf/ci.yml?branch=main&label=CI&logo=github)](https://github.com/k2gl/tuf/actions/workflows/ci.yml)
[![Latest Stable Version](https://img.shields.io/packagist/v/k2gl/tuf?logo=packagist&logoColor=white)](https://packagist.org/packages/k2gl/tuf)
[![Total Downloads](https://img.shields.io/packagist/dt/k2gl/tuf?logo=packagist&logoColor=white)](https://packagist.org/packages/k2gl/tuf)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-level%209-2a5ea7?logo=php&logoColor=white)](https://phpstan.org)
[![License](https://img.shields.io/packagist/l/k2gl/tuf?color=yellowgreen)](https://packagist.org/packages/k2gl/tuf)

A fail-closed TUF (The Update Framework) client for PHP. Starting from an embedded
`root.json` trust anchor, it refreshes signed metadata in the order the spec mandates and
downloads targets, every byte verified against threshold-signed metadata or it throws.

The motivating use case is fetching Sigstore's `trusted_root.json` securely, but
the client is a complete, general TUF implementation with no Sigstore specifics
baked in.

## What it guarantees

Refreshing and downloading enforce, in order and fail-closed, the TUF client
workflow:

1. **Root** — each new root is signed by the threshold of keys of *both* the
   currently trusted root and the new one, and its version increases by exactly
   one (key-compromise and rollback protection).
2. **Timestamp** — signed by the root's timestamp role; neither its version nor
   the snapshot version it points at may roll back.
3. **Snapshot** — matches the length and hashes the timestamp recorded, is signed
   by the root's snapshot role, matches the version the timestamp points at, and
   never rolls back or drops any targets metadata.
4. **Targets** — match the length and hashes the snapshot recorded, signed by the
   delegating role, version matching the snapshot.
5. **Target files** — verified against the length and hashes in the trusted
   targets metadata before the bytes are handed back.

Anything missing, expired, mis-versioned, or insufficiently signed throws. There
is no "best effort" path.

## Install

```bash
composer require k2gl/tuf
```

Requires PHP 8.1+ and `ext-json`. For signature verification, enable the
extension matching the repository's key schemes:

- `ext-sodium` for `ed25519` keys;
- `ext-openssl` for `ecdsa-sha2-nistp256` and `rsassa-pss-sha256` keys.

Any other scheme, an unloadable key, or a malformed signature simply does not
count toward a threshold (fail-closed).

## Usage

You supply the initial `root.json` (the trust anchor — ship it with your
application) and the repository URLs. The updater does the rest.

```php
use K2gl\Tuf\Updater;
use K2gl\Tuf\HttpFetcher;
use K2gl\Tuf\Exception\TufException;

$updater = new Updater(
    trustedRoot:     file_get_contents(__DIR__ . '/root.json'),
    metadataBaseUrl: 'https://tuf-repo-cdn.sigstore.dev',
    targetBaseUrl:   'https://tuf-repo-cdn.sigstore.dev/targets',
    fetcher:         new HttpFetcher(),
);

try {
    $updater->refresh();

    $info = $updater->getTargetInfo('trusted_root.json');

    if ($info === null) {
        throw new RuntimeException('Target is not listed in trusted metadata.');
    }
    $trustedRootJson = $updater->downloadTarget($info); // verified bytes
} catch (TufException $e) {
    // Trust could not be established — fail closed.
    throw $e;
}
```

`getTargetInfo()` walks delegations depth-first (honouring terminating
delegations), fetching delegated targets metadata as needed, and returns `null`
when no trusted role vouches for the path. `downloadTarget()` re-verifies the
content's length and hashes before returning it.

## Keeping it offline (bring your own fetcher)

The network is reached only through the `Fetcher` interface, so the trust logic
itself is pure. Point the client at a local mirror, an HTTP client you already
use, or a fully in-memory source by implementing one method:

```php
use K2gl\Tuf\Fetcher;
use K2gl\Tuf\Exception\DownloadException;

final class MirrorFetcher implements Fetcher
{
    public function fetch(string $url, int $maxLength): string
    {
        // Return at most $maxLength bytes, or throw DownloadException
        // (including for "not found", which ends the root version chain).
    }
}
```

## The trust anchor

The security of the whole chain rests on the initial `root.json` you embed: it is
the one piece that is trusted a priori. Ship it with your application and update
it deliberately. Its own expiry is intentionally not enforced on load — the
refresh immediately walks the root version chain to the latest — but every other
piece of metadata must be current.

## Persisting a rotated root

If the repository has rotated its root since the copy you embedded, `refresh()`
walks the version chain and trusts the newer one for that process — but the
next process still starts from the old embedded `root.json` and has to walk
the same chain again. Persist the latest trusted root after a successful
refresh and load it next time instead of the embedded one:

```php
$updater->refresh();
file_put_contents($localRootPath, $updater->getTrustedRootBytes());
```

## Lower-level API

For advanced or fully offline use, `TrustedMetadataSet` is the verification core
without any I/O: construct it with a trusted root and feed it metadata bytes
(`updateRoot()`, `updateTimestamp()`, `updateSnapshot()`, `updateTargets()`,
`updateDelegatedTargets()`) in workflow order. It applies exactly the checks
listed above and exposes the verified `Root`, `Timestamp`, `Snapshot` and
`Targets` value objects from `K2gl\Tuf\Metadata`.

## Exceptions

Everything thrown implements `K2gl\Tuf\Exception\TufException`:

- `UnsignedMetadataException` — a role's signature threshold was not met.
- `BadVersionException` — a version rollback or a version that disagrees with its
  referring metadata.
- `ExpiredMetadataException` — metadata is past its expiry.
- `LengthOrHashMismatchException` — a downloaded file does not match the trusted
  length or hashes.
- `DownloadException` — a file could not be fetched.
- `RepositoryException` — metadata is malformed or otherwise inconsistent.

## License

MIT — see [LICENSE](LICENSE). An independent, clean-room implementation of the
TUF specification.
