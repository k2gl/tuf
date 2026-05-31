# Changelog

## 1.0.0

First public release: a minimal, fail-closed TUF (The Update Framework) client.

- **`Updater`** — runs the full TUF client workflow against a repository: walks
  the root version chain, then verifies timestamp, snapshot and targets metadata,
  and resolves and downloads individual targets (with delegation traversal),
  verifying every byte against threshold-signed metadata. Throws on any failure.
- **`TrustedMetadataSet`** — the I/O-free verification core: holds the trusted
  root, timestamp, snapshot and targets, and applies the specification's checks
  (threshold signatures, rollback/version, expiry, length/hashes) in order as new
  metadata is fed in.
- **Metadata model** (`K2gl\Tuf\Metadata`) — typed, validated value objects for
  `Root`, `Timestamp`, `Snapshot`, `Targets`, plus `Key`, `Role`,
  `DelegatedRole`, `Delegations`, `MetaFile` and `TargetFile`, with canonical-JSON
  signature verification.
- **`Fetcher`** interface with a default `HttpFetcher`, isolating all network
  access so the client can be pointed at any source or run fully offline.
- Signature schemes: `ed25519` (via `ext-sodium`) and `ecdsa-sha2-nistp256` (via
  `ext-openssl`). `rsassa-pss-sha256` is not verified and never counts toward a
  threshold (fail-closed).
- Every error implements `K2gl\Tuf\Exception\TufException`:
  `UnsignedMetadataException`, `BadVersionException`, `ExpiredMetadataException`,
  `LengthOrHashMismatchException`, `DownloadException` and `RepositoryException`.
