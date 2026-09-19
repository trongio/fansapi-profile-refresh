<?php

namespace App\Refresh\Clients;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Store for the active signing rules and the CDN-issued x-bc value.
 *
 * The active rules are written only by OnlyfansRuleRefresher, after local
 * extraction and verification. They have no TTL: they are the last-known-good
 * copy and survive any failed discovery, extraction or canary. There is no
 * remote rules feed and no committed fallback.
 */
class OnlyfansRules
{
    public const ACTIVE_KEY = 'fansapi:onlyfans:rules:active';

    public const PREVIOUS_KEY = 'fansapi:onlyfans:rules:previous';

    public const STATUS_KEY = 'fansapi:onlyfans:rules:status';

    public const LOCK_KEY = 'fansapi:onlyfans:rules:lock';

    public const REQUESTED_KEY = 'fansapi:onlyfans:rules:requested';

    public const REQUEST_COOLDOWN_KEY = 'fansapi:onlyfans:rules:request-cooldown';

    public const DEVICE_KEY = 'fansapi:onlyfans:x-bc';

    public function current(): ?OnlyfansRuleSet
    {
        $record = Cache::get(self::ACTIVE_KEY);
        if (! is_array($record) || ! is_array($record['rules'] ?? null)) {
            return null;
        }

        try {
            return OnlyfansRuleSet::fromArray($record['rules'], (string) ($record['source'] ?? 'rulegen'));
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function activeRecord(): ?array
    {
        $record = Cache::get(self::ACTIVE_KEY);

        return is_array($record) ? $record : null;
    }

    /** Previous active copy moves aside; the new one becomes last-known-good. */
    public function activate(OnlyfansRuleSet $rules, string $verified): void
    {
        if (($active = $this->activeRecord()) !== null) {
            Cache::forever(self::PREVIOUS_KEY, $active);
        }

        Cache::forever(self::ACTIVE_KEY, [
            'rules' => $rules->toArray(),
            'source' => $rules->source,
            'verified' => $verified,
            'activated_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        $status = Cache::get(self::STATUS_KEY);

        return is_array($status) ? $status : null;
    }

    /** @param array<string, mixed> $status */
    public function recordStatus(array $status): void
    {
        Cache::forever(self::STATUS_KEY, $status + ['attempted_at' => now()->toIso8601String()]);
    }

    /**
     * Ask the scheduler for one refresh. Rate limited, so a burst of rejected
     * profile requests produces a single request, not a storm.
     */
    public function requestRefresh(string $reason): bool
    {
        $cooldown = (int) config('fansapi.onlyfans.rules_request_cooldown_seconds');
        if (! Cache::add(self::REQUEST_COOLDOWN_KEY, 1, $cooldown)) {
            return false;
        }

        Cache::put(self::REQUESTED_KEY, substr($reason, 0, 120), $cooldown * 10);

        return true;
    }

    public function refreshRequested(): ?string
    {
        $reason = Cache::get(self::REQUESTED_KEY);

        return is_string($reason) ? $reason : null;
    }

    public function clearRefreshRequest(): void
    {
        Cache::forget(self::REQUESTED_KEY);
    }

    public function browserCode(): ?string
    {
        $cached = Cache::get(self::DEVICE_KEY);
        if (self::validBrowserCode($cached)) {
            return $cached;
        }

        // Missing, expired, or not a CDN-issued value (an older build stored a
        // random id here with no TTL): fetch a real one.
        $value = strtolower(trim((string) $this->fetchText((string) config('fansapi.onlyfans.x_bc_url'), 256)));
        if (! self::validBrowserCode($value)) {
            Cache::forget(self::DEVICE_KEY);

            return null;
        }

        Cache::put(self::DEVICE_KEY, $value, (int) config('fansapi.onlyfans.x_bc_ttl_seconds'));

        return $value;
    }

    private static function validBrowserCode(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}\z/', $value) === 1;
    }

    /** x-bc is cheap to refetch; the signing rules are never dropped here. */
    public function forgetBrowserCode(): void
    {
        Cache::forget(self::DEVICE_KEY);
    }

    private function fetchText(string $url, int $limit): ?string
    {
        if ($url === '' || $limit < 1) {
            return null;
        }

        try {
            $response = Http::connectTimeout(3)
                ->timeout(5)
                ->withOptions(['stream' => true, 'http_errors' => false, 'allow_redirects' => false])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $stream = $response->toPsrResponse()->getBody();
            $buffer = '';

            try {
                while (! $stream->eof()) {
                    $chunk = $stream->read(8192);
                    if ($chunk === '') {
                        break;
                    }

                    $buffer .= $chunk;
                    if (strlen($buffer) > $limit) {
                        return null;
                    }
                }
            } finally {
                $stream->close();
            }

            return $buffer;
        } catch (Throwable) {
            return null;
        }
    }
}
