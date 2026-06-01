<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

use RuntimeException;

/**
 * The repository served metadata that is malformed, or violates a TUF
 * consistency rule that is not covered by a more specific exception.
 */
class RepositoryException extends RuntimeException implements TufException {}
