<?php

declare(strict_types=1);

namespace K2gl\Tuf;

use K2gl\Tuf\Exception\DownloadException;

use function sprintf;

/**
 * The default {@see Fetcher}: an HTTPS client built on cURL when available,
 * falling back to a stream context. It enforces the byte cap, treats any non-2xx
 * status as a failure, and follows no redirects by default.
 */
final class HttpFetcher implements Fetcher
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function fetch(string $url, int $maxLength): string
    {
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            throw new DownloadException(sprintf('Refusing to fetch non-HTTP(S) URL "%s".', $url));
        }
        $body = \function_exists('curl_init')
            ? $this->fetchWithCurl($url, $maxLength)
            : $this->fetchWithStream($url, $maxLength);

        if (\strlen($body) > $maxLength) {
            throw new DownloadException(sprintf('Response for "%s" exceeds %d bytes.', $url, $maxLength));
        }

        return $body;
    }

    private function fetchWithCurl(string $url, int $maxLength): string
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new DownloadException(sprintf('Could not initialise a request for "%s".', $url));
        }
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_FAILONERROR, false);
        curl_setopt($handle, CURLOPT_BUFFERSIZE, 16384);
        curl_setopt($handle, CURLOPT_NOPROGRESS, false);
        curl_setopt(
            $handle,
            CURLOPT_PROGRESSFUNCTION,
            static fn ($_h, int $downTotal, int $downNow): int => $downNow > $maxLength ? 1 : 0,
        );

        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $error !== '') {
            throw new DownloadException(sprintf('Failed to fetch "%s": %s', $url, $error === '' ? 'unknown error' : $error));
        }

        if ($status < 200 || $status >= 300) {
            throw new DownloadException(sprintf('Fetching "%s" returned HTTP %d.', $url, $status));
        }

        return $body;
    }

    private function fetchWithStream(string $url, int $maxLength): string
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $this->timeoutSeconds, 'ignore_errors' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new DownloadException(sprintf('Failed to open "%s".', $url));
        }
        $body = stream_get_contents($stream, $maxLength + 1);
        $meta = stream_get_meta_data($stream);
        fclose($stream);

        if ($body === false) {
            throw new DownloadException(sprintf('Failed to read "%s".', $url));
        }
        $this->assertOkStatus($url, $meta['wrapper_data'] ?? null);

        return $body;
    }

    private function assertOkStatus(string $url, mixed $headers): void
    {
        if (!is_array($headers)) {
            return;
        }

        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];

                if ($status < 200 || $status >= 300) {
                    throw new DownloadException(sprintf('Fetching "%s" returned HTTP %d.', $url, $status));
                }
            }
        }
    }
}
