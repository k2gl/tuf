<?php

declare(strict_types=1);

namespace K2gl\Tuf\Metadata;

use K2gl\Tuf\Internal\Crypto;
use K2gl\Tuf\Internal\Json;

/**
 * A public key as it appears in TUF metadata: a key type, a signature scheme,
 * and the public key value. Only the scheme matters for verification; the key
 * type is carried for completeness.
 */
final class Key
{
    public function __construct(
        public readonly string $keytype,
        public readonly string $scheme,
        public readonly string $public,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keyval = Json::object($data, 'keyval');

        return new self(
            keytype: Json::string($data, 'keytype'),
            scheme: Json::string($data, 'scheme'),
            public: Json::string($keyval, 'public'),
        );
    }

    /** Verify a signature (hex-encoded) over the given message bytes under this key. */
    public function verifySignature(string $message, string $signatureHex): bool
    {
        return Crypto::verify($this->scheme, $this->public, $message, $signatureHex);
    }
}
