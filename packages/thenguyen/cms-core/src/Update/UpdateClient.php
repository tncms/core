<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Update;

/**
 * HTTPS transport for the remote update layer (CORE-UPGRADE-2, §security).
 *
 * The SINGLE place remote update HTTP lives. It enforces the security contract
 * for every request:
 *
 *   - HTTPS only — a non-`https://` URL is rejected before any I/O, and the
 *     curl transport pins CURLPROTO_HTTPS on the request AND on redirects so a
 *     302 → http:// cannot downgrade the connection;
 *   - TLS verification is always on (peer + host); it is never disabled;
 *   - downloads are streamed to a file with a hard byte ceiling so a lying
 *     `Content-Length` cannot exhaust the disk.
 *
 * The two transports are injectable so discovery/download are unit-testable
 * without real network. No token or Authorization header is ever attached here;
 * the public feed and public release assets are unauthenticated.
 */
final class UpdateClient
{
    /** Absolute ceiling for a metadata document (feeds are tiny). */
    private const MAX_METADATA_BYTES = 1_048_576; // 1 MiB

    private const USER_AGENT = 'TNCMS-Core-Updater';

    /** @var callable(string,array<string,string>):array{status:int,body:string,error:string} */
    private $httpGet;

    /** @var callable(string,string,array<string,string>,int):array{status:int,bytes:int,error:string} */
    private $httpDownload;

    /**
     * @param  callable(string,array<string,string>):array{status:int,body:string,error:string}|null  $httpGet
     * @param  callable(string,string,array<string,string>,int):array{status:int,bytes:int,error:string}|null  $httpDownload
     */
    public function __construct(?callable $httpGet = null, ?callable $httpDownload = null)
    {
        $this->httpGet = $httpGet ?? [$this, 'curlGet'];
        $this->httpDownload = $httpDownload ?? [$this, 'curlDownload'];
    }

    /** Fetch a metadata document body over HTTPS. */
    public function fetch(string $url): string
    {
        $this->assertHttps($url);
        $r = ($this->httpGet)($url, $this->headers());
        if (($r['error'] ?? '') !== '') {
            throw UpdateException::of('fetch_transport', 'Could not reach the update server: '.$this->redact((string) $r['error']));
        }
        $status = (int) ($r['status'] ?? 0);
        if ($status !== 200) {
            throw UpdateException::of('fetch_http_status', "The update server returned HTTP {$status}.");
        }
        $body = (string) ($r['body'] ?? '');
        if ($body === '' || \strlen($body) > self::MAX_METADATA_BYTES) {
            throw UpdateException::of('fetch_body_invalid', 'The update metadata response was empty or too large.');
        }

        return $body;
    }

    /**
     * Stream a release asset to $destPath over HTTPS, refusing to write more than
     * $maxBytes. Returns the number of bytes written.
     */
    public function download(string $url, string $destPath, int $maxBytes): int
    {
        $this->assertHttps($url);
        if ($maxBytes <= 0) {
            throw UpdateException::of('download_no_ceiling', 'A download size ceiling is required.');
        }
        $dir = \dirname($destPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw UpdateException::of('download_dir', 'Could not create the download staging directory.');
        }
        @unlink($destPath);

        $r = ($this->httpDownload)($url, $destPath, $this->headers(), $maxBytes);
        if (($r['error'] ?? '') !== '') {
            @unlink($destPath);
            throw UpdateException::of('download_transport', 'The download failed: '.$this->redact((string) $r['error']));
        }
        $status = (int) ($r['status'] ?? 0);
        if ($status !== 200) {
            @unlink($destPath);
            throw UpdateException::of('download_http_status', "The download server returned HTTP {$status}.");
        }
        $bytes = (int) ($r['bytes'] ?? 0);
        if (! is_file($destPath) || $bytes <= 0) {
            @unlink($destPath);
            throw UpdateException::of('download_empty', 'The downloaded package was empty.');
        }

        return $bytes;
    }

    // ---- internals ------------------------------------------------------

    private function assertHttps(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($scheme !== 'https' || $host === '') {
            throw UpdateException::of('url_insecure', 'Only HTTPS update URLs are allowed.');
        }
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json'];
    }

    private function redact(string $error): string
    {
        // Defensive: never surface an Authorization header if a custom transport leaked one.
        return (string) preg_replace('/(authorization|token)\s*[:=]\s*\S+/i', '$1: [redacted]', $error);
    }

    /**
     * @param  array<string,string>  $headers
     * @return array{status:int,body:string,error:string}
     */
    private function curlGet(string $url, array $headers): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, $this->secureCurlOptions($url, $headers) + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : '';
        curl_close($ch);

        return ['status' => $status, 'body' => \is_string($body) ? $body : '', 'error' => $error];
    }

    /**
     * @param  array<string,string>  $headers
     * @return array{status:int,bytes:int,error:string}
     */
    private function curlDownload(string $url, string $destPath, array $headers, int $maxBytes): array
    {
        $fh = @fopen($destPath, 'wb');
        if ($fh === false) {
            return ['status' => 0, 'bytes' => 0, 'error' => 'cannot open destination'];
        }
        $ch = curl_init();
        curl_setopt_array($ch, $this->secureCurlOptions($url, $headers) + [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_BUFFERSIZE => 131_072,
            CURLOPT_PROGRESSFUNCTION => static function ($ch, $dlTotal, $dlNow) use ($maxBytes): int {
                // Abort the transfer the moment either the advertised or actual
                // size exceeds the ceiling (return non-zero aborts curl).
                return ($dlTotal > $maxBytes || $dlNow > $maxBytes) ? 1 : 0;
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = $errno !== 0 ? curl_error($ch) : '';
        curl_close($ch);
        fclose($fh);

        if ($errno === CURLE_ABORTED_BY_CALLBACK) {
            @unlink($destPath);

            return ['status' => $status, 'bytes' => 0, 'error' => 'download exceeded the size ceiling'];
        }

        $bytes = is_file($destPath) ? (int) filesize($destPath) : 0;

        return ['status' => $status, 'bytes' => $ok === false ? 0 : $bytes, 'error' => $error];
    }

    /**
     * @param  array<string,string>  $headers
     * @return array<int,mixed>
     */
    private function secureCurlOptions(string $url, array $headers): array
    {
        $flat = [];
        foreach ($headers as $k => $v) {
            $flat[] = "{$k}: {$v}";
        }

        return [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => $flat,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            // TLS is mandatory and never relaxed.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // Restrict both the initial request and any redirect to HTTPS.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        ];
    }
}
