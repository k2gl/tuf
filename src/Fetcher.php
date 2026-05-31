<?php

declare(strict_types=1);

namespace K2gl\Tuf;

use K2gl\Tuf\Exception\DownloadException;

/**
 * Fetches bytes for a URL. This is the only place the library touches the
 * network, so an application can keep TUF offline (or point it at a local
 * mirror) simply by supplying its own implementation.
 *
 * Implementations MUST enforce {@see $maxLength} to defend against endless-data
 * attacks, and MUST throw {@see DownloadException} for any failure — including a
 * "not found" response, which {@see Updater} relies on to detect the end of the
 * root version chain.
 */
interface Fetcher
{
    /**
     * @param  int               $maxLength maximum number of bytes to read
     * @throws DownloadException
     */
    public function fetch(string $url, int $maxLength): string;
}
