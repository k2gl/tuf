<?php

declare(strict_types=1);

namespace K2gl\Tuf\Exception;

/**
 * Base type for every exception thrown by this library. Catching this catches
 * everything TUF-specific.
 */
interface TufException extends \Throwable
{
}
