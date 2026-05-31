<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests\Support;

/**
 * Builds a small but coherent, self-consistent set of TUF metadata for the four
 * top-level roles, with all cross-references (versions, lengths, hashes) wired
 * up so the documents verify against one another. Tests mutate the public
 * fields to produce the specific (valid or malicious) repository state they
 * need.
 */
final class RepoBuilder
{
    public SigningKey $rootKey;
    public SigningKey $timestampKey;
    public SigningKey $snapshotKey;
    public SigningKey $targetsKey;

    public int $rootVersion = 1;
    public int $timestampVersion = 1;
    public int $snapshotVersion = 1;
    public int $targetsVersion = 1;

    public string $rootExpires = '2099-01-01T00:00:00Z';
    public string $timestampExpires = '2099-01-01T00:00:00Z';
    public string $snapshotExpires = '2099-01-01T00:00:00Z';
    public string $targetsExpires = '2099-01-01T00:00:00Z';

    public bool $consistentSnapshot = true;

    public int $rootThreshold = 1;

    /** @var array<string, array{length: int, hashes: array<string, string>, custom?: array<string, mixed>}> */
    public array $targets = [];

    public function __construct()
    {
        $this->rootKey = SigningKey::generate();
        $this->timestampKey = SigningKey::generate();
        $this->snapshotKey = SigningKey::generate();
        $this->targetsKey = SigningKey::generate();
        $this->targets['trusted_root.json'] = [
            'length' => 3,
            'hashes' => ['sha256' => Meta::sha256('abc')],
        ];
    }

    /** @param list<SigningKey>|null $signers defaults to the current root key */
    public function rootDoc(?array $signers = null): string
    {
        $signed = [
            '_type' => 'root',
            'spec_version' => '1.0.0',
            'consistent_snapshot' => $this->consistentSnapshot,
            'version' => $this->rootVersion,
            'expires' => $this->rootExpires,
            'keys' => [
                $this->rootKey->keyid => $this->rootKey->keyObject(),
                $this->timestampKey->keyid => $this->timestampKey->keyObject(),
                $this->snapshotKey->keyid => $this->snapshotKey->keyObject(),
                $this->targetsKey->keyid => $this->targetsKey->keyObject(),
            ],
            'roles' => [
                'root' => ['keyids' => [$this->rootKey->keyid], 'threshold' => $this->rootThreshold],
                'timestamp' => ['keyids' => [$this->timestampKey->keyid], 'threshold' => 1],
                'snapshot' => ['keyids' => [$this->snapshotKey->keyid], 'threshold' => 1],
                'targets' => ['keyids' => [$this->targetsKey->keyid], 'threshold' => 1],
            ],
        ];

        return Meta::document($signed, ...($signers ?? [$this->rootKey]));
    }

    public function targetsDoc(?SigningKey $signer = null): string
    {
        $signed = [
            '_type' => 'targets',
            'spec_version' => '1.0.0',
            'version' => $this->targetsVersion,
            'expires' => $this->targetsExpires,
            'targets' => $this->targets,
        ];

        return Meta::document($signed, $signer ?? $this->targetsKey);
    }

    public function snapshotDoc(?SigningKey $signer = null): string
    {
        $targets = $this->targetsDoc();
        $signed = [
            '_type' => 'snapshot',
            'spec_version' => '1.0.0',
            'version' => $this->snapshotVersion,
            'expires' => $this->snapshotExpires,
            'meta' => [
                'targets.json' => [
                    'version' => $this->targetsVersion,
                    'length' => \strlen($targets),
                    'hashes' => ['sha256' => Meta::sha256($targets)],
                ],
            ],
        ];

        return Meta::document($signed, $signer ?? $this->snapshotKey);
    }

    public function timestampDoc(?SigningKey $signer = null): string
    {
        $snapshot = $this->snapshotDoc();
        $signed = [
            '_type' => 'timestamp',
            'spec_version' => '1.0.0',
            'version' => $this->timestampVersion,
            'expires' => $this->timestampExpires,
            'meta' => [
                'snapshot.json' => [
                    'version' => $this->snapshotVersion,
                    'length' => \strlen($snapshot),
                    'hashes' => ['sha256' => Meta::sha256($snapshot)],
                ],
            ],
        ];

        return Meta::document($signed, $signer ?? $this->timestampKey);
    }
}
