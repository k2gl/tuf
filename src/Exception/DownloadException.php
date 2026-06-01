<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

use RuntimeException;

/**
 * A file could not be fetched from the repository (transport error, HTTP error
 * status, or it exceeded the maximum allowed length).
 */
class DownloadException extends RuntimeException implements TufException {}
