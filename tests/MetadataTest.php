<?php

declare(strict_types=1);

namespace K2gl\Tuf\Tests;

use K2gl\Tuf\Exception\RepositoryException;
use K2gl\Tuf\Exception\UnsignedMetadataException;
use K2gl\Tuf\Metadata\Metadata;
use K2gl\Tuf\Metadata\Root;
use K2gl\Tuf\Tests\Support\RepoBuilder;
use K2gl\Tuf\Tests\Support\SigningKey;
use PHPUnit\Framework\TestCase;

final class MetadataTest extends TestCase
{
    public function testParsesRootAndVerifiesItsOwnSignature(): void
    {
        $repo = new RepoBuilder();
        $metadata = Metadata::fromJson($repo->rootDoc());

        self::assertInstanceOf(Root::class, $metadata->signed);
        self::assertSame(1, $metadata->signed->version);

        $metadata->signed->verifyDelegate('root', $metadata);
        $this->addToAssertionCount(1);
    }

    public function testRejectsMetadataNotSignedByRole(): void
    {
        $repo = new RepoBuilder();
        // Sign the root payload with an unrelated key, not the configured root key.
        $metadata = Metadata::fromJson($repo->rootDoc([SigningKey::generate()]));

        $root = $metadata->signed;
        self::assertInstanceOf(Root::class, $root);

        $this->expectException(UnsignedMetadataException::class);
        $root->verifyDelegate('root', $metadata);
    }

    public function testRejectsThresholdNotMet(): void
    {
        $repo = new RepoBuilder();
        $repo->rootThreshold = 2; // one signature cannot satisfy a threshold of two
        $metadata = Metadata::fromJson($repo->rootDoc());

        $root = $metadata->signed;
        self::assertInstanceOf(Root::class, $root);

        $this->expectException(UnsignedMetadataException::class);
        $root->verifyDelegate('root', $metadata);
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(RepositoryException::class);
        Metadata::fromJson('{not json');
    }

    public function testRejectsDocumentWithoutSignedAndSignatures(): void
    {
        $this->expectException(RepositoryException::class);
        Metadata::fromJson('{"signed":{}}');
    }
}
