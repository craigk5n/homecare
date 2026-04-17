<?php

declare(strict_types=1);

namespace HomeCare\Notification;

/**
 * Production {@see HttpClient} backed by ext-curl.
 *
 * Short timeout by default — reminder cron runs every minute and a
 * slow ntfy server shouldn't block the whole loop. Never throws; on
 * any curl-level error the method returns false and logs via
 * `error_log()` so ops sees the failure without the caller having
 * to care.
 */
final class CurlHttpClient implements HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
    ) {}

    public function post(string $url, string $body, array $headers): bool
    {
        $ch = curl_init($url);
        if ($ch === false) {
            error_log("CurlHttpClient: curl_init failed for {$url}");
            return false;
        }

        $headerList = [];
        foreach ($headers as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerList);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->timeoutSeconds);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            error_log("CurlHttpClient: POST {$url} failed: {$error}");
            return false;
        }
        if ($status < 200 || $status >= 300) {
            error_log("CurlHttpClient: POST {$url} returned HTTP {$status}");
            return false;
        }

        return true;
    }
}
