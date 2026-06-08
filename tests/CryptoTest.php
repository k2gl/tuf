<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Internal\Crypto;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

final class CryptoTest extends TestCase
{
    public function testVerifiesEd25519Signature(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = bin2hex(sodium_crypto_sign_publickey($pair));
        $message = 'the signed bytes';
        $signature = bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair)));

        fact(Crypto::verify('ed25519', $public, $message, $signature))->true();
        fact(Crypto::verify('ed25519', $public, 'tampered', $signature))->false();
    }

    public function testVerifiesEcdsaP256SignatureFromPem(): void
    {
        [$pem, $message, $signature] = self::ecdsaFixture();

        fact(Crypto::verify('ecdsa-sha2-nistp256', $pem, $message, $signature))->true();
        fact(Crypto::verify('ecdsa-sha2-nistp256', $pem, 'tampered', $signature))->false();
    }

    public function testVerifiesEcdsaP256SignatureFromHexPoint(): void
    {
        [, $message, $signature, $point] = self::ecdsaFixture();

        fact(Crypto::verify('ecdsa-sha2-nistp256', $point, $message, $signature))->true();
    }

    public function testRejectsUnsupportedScheme(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = bin2hex(sodium_crypto_sign_publickey($pair));
        $message = 'x';
        $signature = bin2hex(sodium_crypto_sign_detached($message, sodium_crypto_sign_secretkey($pair)));

        fact(Crypto::verify('rsassa-pss-sha512', $public, $message, $signature))->false();
    }

    public function testRejectsMalformedSignatureHex(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $public = bin2hex(sodium_crypto_sign_publickey($pair));

        fact(Crypto::verify('ed25519', $public, 'x', 'not-hex'))->false();
    }

    /** @return array{string, string, string, string} PEM, message, hex signature, hex uncompressed point */
    private static function ecdsaFixture(): array
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        fact($key)->notFalse();
        $details = openssl_pkey_get_details($key);
        fact($details)->isArray();
        /** @var array{key: string, ec: array{x: string, y: string}} $details */
        $pem = $details['key'];
        // Left-pad each coordinate to 32 bytes; OpenSSL may strip a leading zero.
        $x = str_pad(bin2hex($details['ec']['x']), 64, '0', STR_PAD_LEFT);
        $y = str_pad(bin2hex($details['ec']['y']), 64, '0', STR_PAD_LEFT);
        $point = '04' . $x . $y;

        $message = 'message to sign';
        $signature = '';
        fact(openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256))->true();

        return [$pem, $message, bin2hex($signature), $point];
    }

    public function testVerifiesRsaPssSha256Signature(): void
    {
        // Cross-implementation vector: signed by OpenSSL with rsa_pss_saltlen:digest
        // (salt length = digest length), the parameters securesystemslib uses.
        $public = self::rsaPssFixture('public.pem');
        $message = self::rsaPssFixture('message.bin');
        $signature = trim(self::rsaPssFixture('signature.hex'));

        fact(Crypto::verify('rsassa-pss-sha256', $public, $message, $signature))->true();
        fact(Crypto::verify('rsassa-pss-sha256', $public, 'tampered', $signature))->false();
        fact(Crypto::verify('rsassa-pss-sha256', self::rsaPssFixture('public-other.pem'), $message, $signature))->false();

        $flipped = substr($signature, 0, -1) . ($signature[-1] === '0' ? '1' : '0');
        fact(Crypto::verify('rsassa-pss-sha256', $public, $message, $flipped))->false();
    }

    private static function rsaPssFixture(string $name): string
    {
        $contents = file_get_contents(__DIR__ . '/fixtures/rsa-pss/' . $name);
        fact($contents)->isString();

        return $contents;
    }
}
