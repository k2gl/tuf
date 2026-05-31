<?php

declare(strict_types=1);

namespace K2gl\Tuf\Internal;

/**
 * Signature verification for the key schemes this version supports:
 *
 *  - `ed25519`             — public key as 64-char hex (32 raw bytes), via ext-sodium;
 *  - `ecdsa-sha2-nistp256` — public key as a PEM SubjectPublicKeyInfo or as the
 *                            hex of an uncompressed P-256 point, via ext-openssl.
 *
 * Any other scheme, a key this build cannot load, or a malformed signature all
 * yield `false` (the signature does not count) rather than throwing: TUF counts
 * valid signatures toward a threshold, so an unusable one must simply not count.
 * `rsassa-pss-sha256` is therefore unsupported in this version and never counts.
 *
 * @see https://theupdateframework.github.io/specification/latest/#file-formats-keys
 */
final class Crypto
{
    /** DER SubjectPublicKeyInfo prefix for an uncompressed NIST P-256 public key (id-ecPublicKey + prime256v1). */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function verify(string $scheme, string $publicKey, string $message, string $signatureHex): bool
    {
        $signature = self::hexToBin($signatureHex);

        if ($signature === null) {
            return false;
        }

        return match ($scheme) {
            'ed25519' => self::verifyEd25519($publicKey, $message, $signature),
            'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp256-v2' => self::verifyEcdsaP256($publicKey, $message, $signature),
            default => false,
        };
    }

    private static function verifyEd25519(string $publicKeyHex, string $message, string $signature): bool
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        $publicKey = self::hexToBin($publicKeyHex);

        if ($publicKey === null || \strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        if (\strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signature, $message, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    private static function verifyEcdsaP256(string $publicKey, string $message, string $signature): bool
    {
        if (!\function_exists('openssl_verify')) {
            return false;
        }
        $pem = self::ecdsaPublicKeyPem($publicKey);

        if ($pem === null) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }

        // ECDSA signatures in TUF metadata are DER-encoded; openssl_verify expects exactly that.
        $result = openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    private static function ecdsaPublicKeyPem(string $publicKey): ?string
    {
        $trimmed = trim($publicKey);

        if (str_starts_with($trimmed, '-----BEGIN ')) {
            return $trimmed;
        }

        // Otherwise treat it as the hex of an uncompressed point (0x04 || X || Y).
        $point = self::hexToBin($trimmed);

        if ($point === null || \strlen($point) !== 65 || $point[0] !== "\x04") {
            return null;
        }
        $der = self::hexToBin(self::P256_SPKI_PREFIX) . $point;
        $pem = chunk_split(base64_encode($der), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n" . $pem . "-----END PUBLIC KEY-----\n";
    }

    private static function hexToBin(string $hex): ?string
    {
        if ($hex === '' || \strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
            return null;
        }
        $bin = @hex2bin($hex);

        return $bin === false ? null : $bin;
    }
}
