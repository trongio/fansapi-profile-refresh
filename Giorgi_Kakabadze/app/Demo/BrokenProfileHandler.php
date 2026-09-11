<?php

namespace App\Demo;

/**
 * ============================ DEMO ONLY ============================
 * The incident's handler, reproduced exactly. It is deliberately wrong:
 *
 *   1. it reads ONLY the top-level `likes` key, so the new nested payload
 *      looks like "no likes";
 *   2. a missing `likes` silently becomes 0 instead of being rejected;
 *   3. it never looks at the HTTP status, so 429 and 500 are "successful"
 *      refreshes that overwrite good data with zero.
 *
 * Nothing in app/Refresh can reach this class, and nothing dispatches
 * BrokenRefreshJob except `php artisan fans:demo broken`. It exists so the
 * regression is rerunnable, not so it can be switched back on.
 * ===================================================================
 */
final class BrokenProfileHandler
{
    /** @param array<string,mixed>|null $body */
    public static function likesFrom(?array $body): int
    {
        return (int) ($body['likes'] ?? 0);
    }
}
