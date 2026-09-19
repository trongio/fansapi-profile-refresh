<?php

/*
|----------------------------------------------------------------------------
| FansAPI profile refresh settings
|----------------------------------------------------------------------------
| Every timeout / budget the README discusses lives here so the values in the
| write-up and the values the workers actually use cannot drift apart.
*/

return [

    // Default source for new demo data: "fixture" (private local upstream).
    // Live accounts carry their own source: "onlyfans" (direct, the default
    // live route) or "ofapi" (managed provider, the optional fallback).
    'source' => env('PROFILE_SOURCE', 'fixture'),
    'live_source' => env('LIVE_SOURCE', 'onlyfans'),

    // Direct anonymous OnlyFans access. Signing rules are extracted locally
    // from the current web build by the isolated internal rulegen service
    // (services/rulegen) and verified here before use. There is no remote
    // rules feed; PHP workers never execute downloaded JavaScript.
    'onlyfans' => [
        'base_url' => rtrim((string) env('ONLYFANS_BASE_URL', 'https://onlyfans.com'), '/'),
        // Internal service address only; nothing is ever forwarded to it.
        'rulegen_url' => env('ONLYFANS_RULEGEN_URL', 'http://rulegen:8080'),
        'rulegen_timeout_seconds' => (int) env('ONLYFANS_RULEGEN_TIMEOUT', 90),
        'rulegen_max_body_bytes' => 65536,
        // Scheduled check cadence (a same-build check is one homepage fetch).
        'rules_refresh_minutes' => (int) env('ONLYFANS_RULES_REFRESH_MINUTES', 30),
        // Live canary before activation: one signed request that must return 200.
        'rules_canary' => (bool) env('ONLYFANS_RULES_CANARY', true),
        'canary_username' => env('ONLYFANS_CANARY_USERNAME', 'onlyfans'),
        // A burst of rejections asks for at most one refresh per window.
        'rules_request_cooldown_seconds' => (int) env('ONLYFANS_RULES_REQUEST_COOLDOWN', 120),
        // A rejected run is retried once, after this delay, and only if a
        // newer verified revision has been activated by then.
        'signature_retry_delay_seconds' => (int) env('ONLYFANS_SIGNATURE_RETRY_DELAY', 90),
        'x_bc_url' => env('ONLYFANS_X_BC_URL', 'https://cdn2.onlyfans.com/key/'),
        'x_bc_ttl_seconds' => (int) env('ONLYFANS_X_BC_TTL', 900),
        'user_agent' => env('ONLYFANS_USER_AGENT', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36'),
        'workspace' => 'onlyfans-direct',
        'requests_per_minute' => (int) env('ONLYFANS_REQUESTS_PER_MINUTE', 30),
    ],

    'ofapi' => [
        'base_url' => rtrim((string) env('OFAPI_BASE_URL', 'https://app.onlyfansapi.com/api'), '/'),
        'token' => env('OFAPI_TOKEN'),
        // The provider bills a workspace-wide quota shared by every key,
        // and cached reads consume it too, so admission is workspace scoped.
        'workspace' => env('OFAPI_WORKSPACE', 'default'),
        'requests_per_minute' => (int) env('OFAPI_REQUESTS_PER_MINUTE', 60),
    ],

    'fixture' => [
        'base_url' => rtrim((string) env('FIXTURE_BASE_URL', 'http://fixture:9000'), '/'),
    ],

    'http' => [
        'connect_timeout' => (int) env('HTTP_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('HTTP_TIMEOUT', 10),
        // Hard cap applied while the body is still being received.
        'max_body_bytes' => (int) env('HTTP_MAX_BODY_BYTES', 1048576), // 1 MiB
    ],

    'refresh' => [
        // Upstream HTTP requests a single logical run may ever make.
        'http_budget' => (int) env('REFRESH_HTTP_BUDGET', 5),
        // Queue deliveries (Laravel counts a release as an attempt).
        'delivery_guard' => (int) env('REFRESH_DELIVERY_GUARD', 100),
        // Wall-clock deadline for the whole logical run.
        'deadline_minutes' => (int) env('REFRESH_DEADLINE_MINUTES', 5),
        // Per-execution overlap lease, ownership-safe release.
        'lease_seconds' => (int) env('REFRESH_LEASE_SECONDS', 45),
        // A claimed-but-never-delivered run is reconcilable after this.
        'reconcile_after_seconds' => (int) env('REFRESH_RECONCILE_AFTER', 120),
        'batch_size' => 100,
    ],

    'schedule' => [
        'hot_threshold' => 100000,   // strictly above -> hot
        'hot_interval_hours' => 24,
        'cold_interval_hours' => 72,
    ],

    'backoff' => [
        // Bounded backoff with jitter for timeouts / network / 5xx.
        'base_seconds' => (int) env('BACKOFF_BASE_SECONDS', 2),
        'max_seconds' => (int) env('BACKOFF_MAX_SECONDS', 20),
        'jitter_seconds' => (int) env('BACKOFF_JITTER_SECONDS', 2),
        // 429 without a usable Retry-After.
        'throttle_min_seconds' => 3,
        'throttle_max_seconds' => 8,
    ],

    'cooldown' => [
        // Account-level pause after a throttle signal.
        'account_seconds' => (int) env('ACCOUNT_COOLDOWN_SECONDS', 8),
        // Workspace-level pause (shared provider quota).
        'workspace_seconds' => (int) env('WORKSPACE_COOLDOWN_SECONDS', 10),
    ],

    // Per-job memory sampling inside the worker. Off by default.
    'memory_probe' => (bool) env('MEMORY_PROBE', false),

    'ui' => [
        'per_page' => 25,
        'poll_ms' => 3000,
    ],
];
