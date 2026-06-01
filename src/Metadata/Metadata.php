<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Exception\UnsignedMetadataException;
use K2gl\Tuf\Internal\CanonicalJson;
use K2gl\Tuf\Internal\Json;
use JsonException;
use stdClass;

/**
 * A signed TUF metadata file: its `signed` payload, the signatures over it, and
 * the canonical bytes those signatures are checked against.
 *
 * Parsing never trusts the payload — call {@see Root::verifyDelegate()} or
 * {@see Targets::verifyDelegate()} (which delegate to {@see verifySignedBy()})
 * before relying on the contents.
 */
final class Metadata
{
    /**
     * @param array<string, string> $signatures key id => hex-encoded signature
     */
    private function __construct(
        public readonly Signed $signed,
        private readonly array $signatures,
        private readonly string $signedBytes,
    ) {}

    public static function fromJson(string $json): self
    {
        try {
            /** @var mixed $root */
            $root = json_decode($json, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RepositoryException('Metadata is not valid JSON: ' . $e->getMessage(), previous: $e);
        }

        if (! $root instanceof stdClass || ! isset($root->signed) || ! isset($root->signatures)) {
            throw new RepositoryException('Metadata must be an object with "signed" and "signatures".');
        }

        if (! $root->signed instanceof stdClass) {
            throw new RepositoryException('Metadata "signed" must be an object.');
        }

        // Canonical bytes come from the object tree (objects vs arrays preserved);
        // the typed payload is parsed from an associative view of the same data.
        $signedBytes = CanonicalJson::encode($root->signed);
        /** @var array<string, mixed> $signedArray */
        $signedArray = json_decode(json_encode($root->signed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        return new self(
            signed: Signed::fromArray($signedArray),
            signatures: self::signatures($root->signatures),
            signedBytes: $signedBytes,
        );
    }

    /**
     * Count the valid signatures from keys the role authorises and throw unless
     * the role's threshold is met. Each key id counts at most once.
     *
     * @param array<string, Key> $keys
     */
    public function verifySignedBy(string $roleName, array $keys, Role $role): void
    {
        $verified = [];

        foreach ($role->keyids as $keyid) {
            if (isset($verified[$keyid])) {
                continue;
            }
            $key = $keys[$keyid] ?? null;
            $signature = $this->signatures[$keyid] ?? null;

            if ($key === null || $signature === null) {
                continue;
            }

            if ($key->verifySignature($this->signedBytes, $signature)) {
                $verified[$keyid] = true;
            }
        }

        if (\count($verified) < $role->threshold) {
            throw new UnsignedMetadataException(\sprintf(
                'Role "%s" requires %d signature(s) but only %d verified.',
                $roleName,
                $role->threshold,
                \count($verified),
            ));
        }
    }

    /**
     * @param  mixed                  $signatures decoded "signatures" value
     * @return array<string, string>
     */
    private static function signatures(mixed $signatures): array
    {
        if (! is_array($signatures)) {
            throw new RepositoryException('Metadata "signatures" must be an array.');
        }
        $result = [];

        foreach ($signatures as $signature) {
            if (! $signature instanceof stdClass) {
                throw new RepositoryException('Each signature must be an object.');
            }
            /** @var array<string, mixed> $entry */
            $entry = (array) $signature;
            // Last signature for a key id wins; threshold counting is by distinct key id.
            $result[Json::string($entry, 'keyid')] = Json::string($entry, 'sig');
        }

        return $result;
    }
}
