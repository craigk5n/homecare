<?php

declare(strict_types=1);

namespace HomeCare\Auth;

/**
 * Result of evaluating a session against the configured timeout.
 *
 * - {@see self::Active}: within the window, proceed with the request.
 * - {@see self::Expired}: timed out, destroy the session and re-auth.
 * - {@see self::New}: no prior activity recorded; first authenticated
 *   request of this session. Callers treat it like Active but know to
 *   initialise `last_activity`.
 */
enum SessionState: string
{
    case Active = 'active';
    case Expired = 'expired';
    case New = 'new';
}
