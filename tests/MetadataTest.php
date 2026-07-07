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

use function K2gl\PHPUnitFluentAssertions\fact;

final class MetadataTest extends TestCase
{
    public function testParsesRootAndVerifiesItsOwnSignature(): void
    {
        $repo = new RepoBuilder;
        $metadata = Metadata::fromJson($repo->rootDoc());

        fact($metadata->signed)->instanceOf(Root::class);
        fact($metadata->signed->version)->is(1);

        $metadata->signed->verifyDelegate('root', $metadata);
        $this->addToAssertionCount(1);
    }

    public function testRejectsMetadataNotSignedByRole(): void
    {
        // arrange
        $repo = new RepoBuilder;
        // Sign the root payload with an unrelated key, not the configured root key.
        $metadata = Metadata::fromJson($repo->rootDoc([SigningKey::generate()]));

        $root = $metadata->signed;
        fact($root)->instanceOf(Root::class);

        // act + assert
        fact(static fn () => $root->verifyDelegate('root', $metadata))->throws(UnsignedMetadataException::class);
    }

    public function testRejectsThresholdNotMet(): void
    {
        // arrange
        $repo = new RepoBuilder;
        $repo->rootThreshold = 2; // one signature cannot satisfy a threshold of two
        $metadata = Metadata::fromJson($repo->rootDoc());

        $root = $metadata->signed;
        fact($root)->instanceOf(Root::class);

        // act + assert
        fact(static fn () => $root->verifyDelegate('root', $metadata))->throws(UnsignedMetadataException::class);
    }

    public function testRejectsInvalidJson(): void
    {
        // act + assert
        fact(static fn () => Metadata::fromJson('{not json'))->throws(RepositoryException::class);
    }

    public function testRejectsDocumentWithoutSignedAndSignatures(): void
    {
        // act + assert
        fact(static fn () => Metadata::fromJson('{"signed":{}}'))->throws(RepositoryException::class);
    }
}
