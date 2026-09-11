<?php

namespace App\Refresh;

/**
 * Every terminal-or-retryable category a single attempt can produce.
 * Kept as one list so logs, the UI and the retry policy cannot disagree.
 */
final class Outcome
{
    // Success
    public const ACCEPTED = 'refresh_accepted';

    public const VERIFIED_UNCHANGED = 'verified_unchanged';

    public const STALE_IGNORED = 'stale_response_ignored';

    // Retryable
    public const THROTTLED = 'throttled';                 // 429

    public const SERVER_ERROR = 'server_error';           // 5xx

    public const TIMEOUT = 'timeout';

    public const NETWORK_ERROR = 'network_error';

    // Terminal
    public const INVALID_CREDENTIALS = 'invalid_credentials';   // 401

    public const FORBIDDEN = 'forbidden';                       // 403, category unknown

    public const PROFILE_MISSING = 'profile_missing';           // 404

    public const SCHEMA_FAILURE = 'schema_failure';             // invalid 2xx payload

    public const IDENTITY_MISMATCH = 'identity_mismatch';

    public const BODY_TOO_LARGE = 'body_too_large';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const DEADLINE_EXCEEDED = 'deadline_exceeded';

    public const UNEXPECTED_STATUS = 'unexpected_status';

    public const RETRYABLE = [
        self::THROTTLED,
        self::SERVER_ERROR,
        self::TIMEOUT,
        self::NETWORK_ERROR,
    ];

    public static function isRetryable(string $category): bool
    {
        return in_array($category, self::RETRYABLE, true);
    }
}
