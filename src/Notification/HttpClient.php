<?php

declare(strict_types=1);

namespace HomeCare\Notification;

/**
 * Minimum POST surface used by channels that talk HTTP.
 *
 * Injecting this (instead of letting channels call `curl_*` directly)
 * lets tests assert request shape without hitting the network. The
 * production implementation is {@see CurlHttpClient}; tests use an
 * in-memory recorder.
 */
interface HttpClient
{
    /**
     * POST $body to $url with the given headers. Returns true on a
     * 2xx response, false on transport or HTTP error. MUST NOT throw.
     *
     * @param array<string,string> $headers
     */
    public function post(string $url, string $body, array $headers): bool;
}
