<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

/**
 * Metadata is not signed by the threshold of authorised keys for its role.
 */
class UnsignedMetadataException extends RepositoryException
{
}
