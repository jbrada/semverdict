<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Support;

use RuntimeException;

class Http
{
    private const MAX_REDIRECTS = 10;
    private const TIMEOUT_SECONDS = 60;

    /**
     * Some Magento vendor repositories reject requests whose User-Agent does
     * not look like Composer — they answer 401 "composer http-basic
     * authentication required" even when the credentials are valid. So
     * identify as a Composer client while still naming the actual tool.
     */
    private const USER_AGENT = 'Composer/2.8.0 (semverdict; +https://github.com/jbrada/semverdict)';

    /**
     * @param int|null $maxBytes refuse bodies larger than this instead of
     *                           buffering them (Packagist's root index is
     *                           hundreds of megabytes — reading it would
     *                           exhaust memory)
     *
     * @throws RuntimeException on any HTTP or transport failure, or when the
     *                          body exceeds $maxBytes
     */
    public static function get(string $url, ?string $basicAuth = null, ?int $maxBytes = null): string
    {
        [$stream] = self::open($url, $basicAuth);

        if ($maxBytes === null) {
            $body = stream_get_contents($stream);
            fclose($stream);
            if ($body === false) {
                throw new RuntimeException("Reading response body failed for {$url}.");
            }

            return $body;
        }

        $body = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 1 << 16);
            if ($chunk === false) {
                fclose($stream);
                throw new RuntimeException("Reading response body failed for {$url}.");
            }
            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                fclose($stream);
                throw new RuntimeException(sprintf('Response from %s exceeds the %d byte limit.', $url, $maxBytes));
            }
        }
        fclose($stream);

        return $body;
    }

    /**
     * Streams a URL to a local file without buffering the whole body in memory.
     *
     * @throws RuntimeException on any HTTP or transport failure, or when the
     *                          received size does not match the Content-Length header
     */
    public static function download(string $url, string $destPath, ?string $basicAuth = null): void
    {
        [$source, $headers] = self::open($url, $basicAuth);

        $dest = fopen($destPath, 'wb');
        if ($dest === false) {
            fclose($source);
            throw new RuntimeException("Cannot open {$destPath} for writing.");
        }

        $copied = stream_copy_to_stream($source, $dest);
        fclose($source);
        $flushed = fclose($dest);

        $expected = self::headerValue($headers, 'Content-Length');
        if ($copied === false || !$flushed || ($expected !== null && $copied !== (int) $expected)) {
            @unlink($destPath);
            throw new RuntimeException(sprintf('Download of %s is incomplete (%s of %s bytes received).', $url, $copied === false ? 'unknown' : $copied, $expected ?? 'unknown'));
        }
    }

    /**
     * Opens an HTTP stream, following redirects manually: PHP's wrapper resends
     * every custom header on follow_location, which would leak the Authorization
     * header to a different host (e.g. a repo redirecting dist downloads to a CDN).
     * Credentials are only forwarded while the redirect chain stays on the same
     * scheme, host, and port.
     *
     * @return array{0: resource, 1: string[]} the response body stream and its headers
     */
    private static function open(string $url, ?string $basicAuth): array
    {
        $auth = $basicAuth;
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            // Dist URLs come from repository metadata; never fetch anything but http(s)
            // (a hostile repo must not be able to point at file:// or phar:// paths).
            if (!preg_match('#^https?://#i', $url)) {
                throw new RuntimeException("Refusing non-HTTP(S) URL: {$url}");
            }
            error_clear_last();
            $stream = @fopen($url, 'rb', false, self::createContext($auth));
            if ($stream === false) {
                $reason = error_get_last()['message'] ?? 'transport error';
                throw new RuntimeException("HTTP request failed for {$url}: {$reason}");
            }

            $wrapperData = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $headers = is_array($wrapperData)
                ? array_values(array_filter($wrapperData, static fn ($line): bool => is_string($line)))
                : [];
            $status = self::statusCode($headers);
            if ($status >= 200 && $status < 300) {
                return [$stream, $headers];
            }

            fclose($stream);
            if ($status < 300 || $status >= 400) {
                $statusLine = $headers[0] ?? 'no status line';
                throw new RuntimeException("HTTP request failed for {$url} ({$statusLine})");
            }

            $location = self::headerValue($headers, 'Location');
            if ($location === null) {
                throw new RuntimeException("Redirect without Location header for {$url}.");
            }
            $next = self::resolveUrl($url, $location);
            if (!self::sameOrigin($url, $next)) {
                $auth = null;
            }
            $url = $next;
        }

        throw new RuntimeException("Too many redirects for {$url}.");
    }

    /**
     * @return resource
     */
    private static function createContext(?string $basicAuth)
    {
        $headers = ['User-Agent: ' . self::USER_AGENT];
        if ($basicAuth !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($basicAuth);
        }

        return stream_context_create([
            'http' => [
                'header' => implode("\r\n", $headers),
                'follow_location' => 0,
                'ignore_errors' => 1,
                'timeout' => self::TIMEOUT_SECONDS,
            ],
        ]);
    }

    /**
     * @internal public for unit testing only
     *
     * @param string[] $headers
     */
    public static function statusCode(array $headers): int
    {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0] ?? '', $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Returns the last occurrence of a header, case-insensitively.
     *
     * @internal public for unit testing only
     *
     * @param string[] $headers
     */
    public static function headerValue(array $headers, string $name): ?string
    {
        $value = null;
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                $value = trim(substr($header, strlen($name) + 1));
            }
        }

        return $value;
    }

    /**
     * @internal public for unit testing only
     */
    public static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $origin . $dir . '/' . $location;
    }

    /**
     * @internal public for unit testing only
     */
    public static function sameOrigin(string $a, string $b): bool
    {
        $partsA = parse_url($a);
        $partsB = parse_url($b);
        if ($partsA === false || $partsB === false) {
            return false;
        }

        $portA = $partsA['port'] ?? (strtolower($partsA['scheme'] ?? '') === 'https' ? 443 : 80);
        $portB = $partsB['port'] ?? (strtolower($partsB['scheme'] ?? '') === 'https' ? 443 : 80);

        return strtolower($partsA['scheme'] ?? '') === strtolower($partsB['scheme'] ?? '')
            && strtolower($partsA['host'] ?? '') === strtolower($partsB['host'] ?? '')
            && $portA === $portB;
    }
}
