<?php

declare(strict_types=1);

namespace K2gl\Tuf\Internal;

use SodiumException;

/**
 * Signature verification for the key schemes this version supports:
 *
 *  - `ed25519`             — public key as 64-char hex (32 raw bytes), via ext-sodium;
 *  - `ecdsa-sha2-nistp256` — public key as a PEM SubjectPublicKeyInfo or as the
 *                            hex of an uncompressed P-256 point, via ext-openssl;
 *  - `rsassa-pss-sha256`   — public key as a PEM SubjectPublicKeyInfo, via ext-openssl
 *                            (EMSA-PSS-VERIFY: SHA-256, MGF1-SHA256, salt length 32).
 *
 * Any other scheme, a key this build cannot load, or a malformed signature all
 * yield `false` (the signature does not count) rather than throwing: TUF counts
 * valid signatures toward a threshold, so an unusable one must simply not count.
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
            'rsassa-pss-sha256' => self::verifyRsaPss($publicKey, $message, $signature),
            default => false,
        };
    }

    private static function verifyEd25519(string $publicKeyHex, string $message, string $signature): bool
    {
        if (! \function_exists('sodium_crypto_sign_verify_detached')) {
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
        } catch (SodiumException) {
            return false;
        }
    }

    private static function verifyEcdsaP256(string $publicKey, string $message, string $signature): bool
    {
        if (! \function_exists('openssl_verify')) {
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

    /**
     * RSASSA-PSS verification done by hand (ext-openssl, no third-party crypto): the
     * raw RSA public operation recovers the encoded message EM, then EMSA-PSS-VERIFY
     * (RFC 8017 §9.1.2) is applied with SHA-256, MGF1-SHA256 and a salt length of 32
     * bytes — the parameters securesystemslib (python-tuf) signs with.
     */
    private static function verifyRsaPss(string $publicKey, string $message, string $signature): bool
    {
        if (! \function_exists('openssl_public_decrypt')) {
            return false;
        }
        $pem = trim($publicKey);

        if (! str_starts_with($pem, '-----BEGIN ')) {
            return false;
        }
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            return false;
        }
        $details = openssl_pkey_get_details($key);

        if (! \is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return false;
        }
        $modBits = $details['bits'] ?? null;

        if (! \is_int($modBits) || $modBits < 512) {
            return false;
        }
        $emLen = intdiv($modBits + 7, 8);
        $em = '';

        // OPENSSL_NO_PADDING gives the raw RSA result (s^e mod n) — the encoded message EM.
        if (openssl_public_decrypt($signature, $em, $key, OPENSSL_NO_PADDING) === false || \strlen($em) > $emLen) {
            return false;
        }
        $em = str_pad($em, $emLen, "\x00", STR_PAD_LEFT);

        $hLen = 32;
        $sLen = 32;
        $emBits = $modBits - 1;
        $topBits = 8 * $emLen - $emBits;

        if ($emLen < $hLen + $sLen + 2 || $em[$emLen - 1] !== "\xbc") {
            return false;
        }
        $maskedDb = substr($em, 0, $emLen - $hLen - 1);
        $h = substr($em, $emLen - $hLen - 1, $hLen);

        $topMask = (0xff << (8 - $topBits)) & 0xff;

        if ($topBits > 0 && (\ord($maskedDb[0]) & $topMask) !== 0) {
            return false;
        }
        $db = $maskedDb ^ self::mgf1($h, $emLen - $hLen - 1);

        if ($topBits > 0) {
            $db[0] = $db[0] & \chr((0xff >> $topBits) & 0xff);
        }
        $psLen = $emLen - $sLen - $hLen - 2;

        if (substr($db, 0, $psLen) !== str_repeat("\x00", $psLen) || $db[$psLen] !== "\x01") {
            return false;
        }
        $salt = substr($db, $psLen + 1);
        $mPrime = "\x00\x00\x00\x00\x00\x00\x00\x00" . hash('sha256', $message, true) . $salt;

        return hash_equals(hash('sha256', $mPrime, true), $h);
    }

    /** MGF1 with SHA-256 (RFC 8017 §B.2.1). */
    private static function mgf1(string $seed, int $length): string
    {
        $output = '';

        for ($counter = 0; \strlen($output) < $length; $counter++) {
            $output .= hash('sha256', $seed . pack('N', $counter), true);
        }

        return substr($output, 0, $length);
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
        if ($hex === '' || \strlen($hex) % 2 !== 0 || ! ctype_xdigit($hex)) {
            return null;
        }
        $bin = @hex2bin($hex);

        return $bin === false ? null : $bin;
    }
}
