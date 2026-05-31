<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

/**
 * Metadata carries a version number that violates a TUF rule: a rollback to an
 * older version, a snapshot/targets version that does not match its referrer,
 * or a root version that is not exactly one greater than the trusted one.
 */
class BadVersionException extends RepositoryException
{
}
