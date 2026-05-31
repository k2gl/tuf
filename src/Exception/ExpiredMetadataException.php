<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

/**
 * Metadata is past its expiration time and can no longer be trusted.
 */
class ExpiredMetadataException extends RepositoryException
{
}
