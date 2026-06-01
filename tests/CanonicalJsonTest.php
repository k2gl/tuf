<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Internal\CanonicalJson;
use PHPUnit\Framework\TestCase;

use function K2gl\PHPUnitFluentAssertions\fact;

final class CanonicalJsonTest extends TestCase
{
    private static function canonical(string $json): string
    {
        return CanonicalJson::encode(json_decode($json, false, flags: JSON_THROW_ON_ERROR));
    }

    public function testSortsObjectKeysByByteValue(): void
    {
        fact(self::canonical('{"z":1,"a":2}'))->is('{"a":2,"z":1}');
    }

    public function testSortsNestedObjectsRecursively(): void
    {
        fact(self::canonical('{"b":{"d":1,"c":2},"a":3}'))->is('{"a":3,"b":{"c":2,"d":1}}');
    }

    public function testKeepsEmptyObjectAndArrayDistinct(): void
    {
        fact(self::canonical('{}'))->is('{}');
        fact(self::canonical('[]'))->is('[]');
    }

    public function testRendersScalarsWithoutWhitespace(): void
    {
        fact(self::canonical('{"a":1, "b":[true, false, null], "c":"x"}'))
            ->is('{"a":1,"b":[true,false,null],"c":"x"}');
    }

    public function testEscapesOnlyBackslashAndDoubleQuote(): void
    {
        // PHP string: he said "hi"\  ->  "he said \"hi\"\\"
        fact(CanonicalJson::encode('he said "hi"\\'))->is('"he said \\"hi\\"\\\\"');
    }

    public function testDoesNotEscapeUnicodeOrSlashes(): void
    {
        fact(CanonicalJson::encode('café/x'))->is('"café/x"');
    }

    public function testRendersIntegers(): void
    {
        fact(CanonicalJson::encode(0))->is('0');
        fact(CanonicalJson::encode(-3))->is('-3');
        fact(CanonicalJson::encode(42))->is('42');
    }

    public function testRejectsFloats(): void
    {
        $this->expectException(RepositoryException::class);
        CanonicalJson::encode(1.5);
    }
}
