<?php
/**
 * Shared bootstrap for every v1 API endpoint.
 *
 * Not loaded via HTTP directly (leading underscore + .htaccess deny
 * elsewhere); consumed by sibling *.php files via `require`.
 *
 * Responsibilities:
 *   - Autoload + config + DB connection
 *   - Expose api_authenticate_or_exit(), api_send(ApiResponse) helpers
 *   - NO session, NO HTML; JSON in, JSON out
 */

declare(strict_types=1);

if (!defined('_ISVALID')) {
    define('_ISVALID', true);
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/dbi4php.php';

use HomeCare\Api\ApiAuth;
use HomeCare\Api\ApiResponse;
use HomeCare\Auth\ApiAuthResult;
use HomeCare\Database\DbiAdapter;
use HomeCare\RateLimit\ApiRateLimiter;
use HomeCare\Repository\UserRepository;

do_config();

$GLOBALS['c'] = @dbi_connect($db_host, $db_login, $db_password, $db_database);
if (!$GLOBALS['c']) {
    api_send(ApiResponse::error('Database unavailable', 503));
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$limiter = new ApiRateLimiter(new DbiAdapter());
$unauth_rpm = $limiter->getConfigValue('api_rate_limit_rpm', 60);
$auth_rpm = $limiter->getConfigValue('api_rate_limit_authenticated_rpm', 600);

$has_bearer_header = isset($_SERVER['HTTP_AUTHORIZATION']) && str_starts_with(strtolower($_SERVER['HTTP_AUTHORIZATION'] ?? ''), 'bearer ');

if ($has_bearer_header) {
    if (!$limiter->isUnderLimit($ip, $unauth_rpm, 'bearer_attempts')) {
        header('Retry-After: 60');
        api_send(ApiResponse::error('rate_limited', 429));
    }
}

/**
 * Emit an {@see ApiResponse} and terminate.
 */
function api_send(ApiResponse $r): never
{
    http_response_code($r->httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo $r->toJson();
    exit;
}

/**
 * Decode the request body as JSON. Returns `[]` for an empty body so
 * the handler's own "missing field" validation produces the right
 * error message.
 *
 * @return array<string,mixed>
 */
function api_parse_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        api_send(ApiResponse::error('request body is not valid JSON', 400));
    }
    if (!is_array($decoded)) {
        return [];
    }

    /** @var array<string,mixed> $decoded */
    return $decoded;
}

/**
 * Authenticate the current request via Authorization: Bearer &lt;key&gt;.
 *
 * @return ApiAuthResult
 */
function api_try_authenticate(): ApiAuthResult
{
  $users = new UserRepository(new DbiAdapter());
  return (new ApiAuth($users))-&gt;authenticate($_SERVER);
}

    return $result->user;
}
