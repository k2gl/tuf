<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    private static function canonical(string $json): string
    {
        return CanonicalJson::encode(json_decode($json, false, flags: JSON_THROW_ON_ERROR));
    }

    public function testSortsObjectKeysByByteValue(): void
    {
        self::assertSame('{"a":2,"z":1}', self::canonical('{"z":1,"a":2}'));
    }

    public function testSortsNestedObjectsRecursively(): void
    {
        self::assertSame(
            '{"a":3,"b":{"c":2,"d":1}}',
            self::canonical('{"b":{"d":1,"c":2},"a":3}'),
        );
    }

    public function testKeepsEmptyObjectAndArrayDistinct(): void
    {
        self::assertSame('{}', self::canonical('{}'));
        self::assertSame('[]', self::canonical('[]'));
    }

    public function testRendersScalarsWithoutWhitespace(): void
    {
        self::assertSame(
            '{"a":1,"b":[true,false,null],"c":"x"}',
            self::canonical('{"a":1, "b":[true, false, null], "c":"x"}'),
        );
    }

    public function testEscapesOnlyBackslashAndDoubleQuote(): void
    {
        // PHP string: he said "hi"\  ->  "he said \"hi\"\\"
        self::assertSame('"he said \\"hi\\"\\\\"', CanonicalJson::encode('he said "hi"\\'));
    }

    public function testDoesNotEscapeUnicodeOrSlashes(): void
    {
        self::assertSame('"café/x"', CanonicalJson::encode('café/x'));
    }

    public function testRendersIntegers(): void
    {
        self::assertSame('0', CanonicalJson::encode(0));
        self::assertSame('-3', CanonicalJson::encode(-3));
        self::assertSame('42', CanonicalJson::encode(42));
    }

    public function testRejectsFloats(): void
    {
        $this->expectException(RepositoryException::class);
        CanonicalJson::encode(1.5);
    }
}
