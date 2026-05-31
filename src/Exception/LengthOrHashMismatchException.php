<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

/**
 * A downloaded file does not match the length or hashes recorded for it in the
 * trusted metadata that refers to it.
 */
class LengthOrHashMismatchException extends RepositoryException
{
}
