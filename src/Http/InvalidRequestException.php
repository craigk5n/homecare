<?php

declare(strict_types=1);

namespace HomeCare\Http;

use RuntimeException;

/**
 * Thrown by {@see Request} when input contains a dangerous HTML tag or fails
 * validation. Handlers can catch this to render a user-facing error; the
 * legacy `preventHacking()` in `includes/formvars.php` called
 * `die_miserable_death()` directly, which we move up to the caller so
 * the HTTP abstraction stays side-effect-free.
 */
final class InvalidRequestException extends RuntimeException
{
}
