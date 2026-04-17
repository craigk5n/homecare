<?php

declare(strict_types=1);

namespace HomeCare\Api;

/**
 * JSON response envelope for the v1 API.
 *
 * Every endpoint returns one of:
 *   - `{"status":"ok","data":...}`   with an appropriate 2xx HTTP status
 *   - `{"status":"error","message":"..."}`  with 4xx/5xx
 *
 * The value object separates "what" (data/message) from "how to send"
 * (HTTP status + JSON serialisation), so handlers can return it as a
 * pure value and the HTTP wrapper handles transport.
 */
final class ApiResponse
{
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    /**
     * @param 'ok'|'error'                         $status
     * @param array<string,mixed>|list<mixed>|null $data
     */
    public function __construct(
        public readonly string $status,
        public readonly ?array $data,
        public readonly ?string $message,
        public readonly int $httpStatus,
    ) {}

    /**
     * @param array<string,mixed>|list<mixed> $data
     */
    public static function ok(array $data, int $httpStatus = 200): self
    {
        return new self(self::STATUS_OK, $data, null, $httpStatus);
    }

    public static function error(string $message, int $httpStatus): self
    {
        return new self(self::STATUS_ERROR, null, $message, $httpStatus);
    }

    /**
     * JSON body matching the envelope convention.
     */
    public function toJson(int $jsonFlags = JSON_UNESCAPED_SLASHES): string
    {
        $payload = $this->status === self::STATUS_OK
            ? ['status' => self::STATUS_OK, 'data' => $this->data]
            : ['status' => self::STATUS_ERROR, 'message' => (string) $this->message];

        $json = json_encode($payload, $jsonFlags);

        return $json === false ? '{"status":"error","message":"encode failed"}' : $json;
    }
}
