<?php

namespace App\Refresh\Clients;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;
use UnexpectedValueException;

/**
 * Store for the signers, keyed by web build, plus the CDN-issued x-bc value
 * and the rotation metrics.
 *
 *   rules:build:{revision}   the verified signer for that build (no TTL)
 *   rules:active             pointer: revision workers sign with
 *   rules:previous           pointer: the build it replaced
 *   rules:builds             the last few builds kept, newest first
 *
 * Signers are written only by OnlyfansRuleRefresher after local extraction
 * and verification. The active one is last-known-good: no TTL, and no failed
 * discovery, extraction or canary ever replaces it. There is no remote rules
 * feed and no committed fallback.
 */
class OnlyfansRules
{
    public const ACTIVE_KEY = 'fansapi:onlyfans:rules:active';

    public const PREVIOUS_KEY = 'fansapi:onlyfans:rules:previous';

    public const BUILDS_KEY = 'fansapi:onlyfans:rules:builds';

    public const BUILD_KEY_PREFIX = 'fansapi:onlyfans:rules:build:';

    public const STATUS_KEY = 'fansapi:onlyfans:rules:status';

    public const CANARY_KEY = 'fansapi:onlyfans:rules:canary';

    public const LOCK_KEY = 'fansapi:onlyfans:rules:lock';

    public const REQUESTED_KEY = 'fansapi:onlyfans:rules:requested';

    public const REQUEST_COOLDOWN_KEY = 'fansapi:onlyfans:rules:request-cooldown';

    public const REJECTIONS_KEY_PREFIX = 'fansapi:onlyfans:rules:rejections:';

    public const FIRST_REJECTION_KEY_PREFIX = 'fansapi:onlyfans:rules:first-rejection:';

    public const DEVICE_KEY = 'fansapi:onlyfans:x-bc';

    private const KEEP_BUILDS = 5;

    private const METRIC_TTL_SECONDS = 30 * 86400;

    public function current(): ?OnlyfansRuleSet
    {
        $revision = Cache::get(self::ACTIVE_KEY);

        // Written before signers were keyed by build: the record itself.
        // Move it under its build key once; idempotent, so racing workers
        // write the same thing.
        if (is_array($revision) && is_array($revision['rules'] ?? null)) {
            try {
                $rules = OnlyfansRuleSet::fromArray($revision['rules'], (string) ($revision['source'] ?? 'rulegen'));
            } catch (UnexpectedValueException) {
                return null;
            }
            Cache::forever(self::BUILD_KEY_PREFIX.$rules->revision, [
                'rules' => $rules->toArray(),
                'source' => $rules->source,
                'mode' => $rules->mode,
            ] + array_intersect_key($revision, array_flip(['verified', 'activated_at'])));
            Cache::forever(self::ACTIVE_KEY, $rules->revision);
            Cache::forever(self::BUILDS_KEY, array_values(array_unique([$rules->revision, ...$this->builds()])));

            return $rules;
        }

        return is_string($revision) ? $this->build($revision) : null;
    }

    public function build(string $revision): ?OnlyfansRuleSet
    {
        $record = $this->buildRecord($revision);
        if ($record === null || ! is_array($record['rules'] ?? null)) {
            return null;
        }

        try {
            return OnlyfansRuleSet::fromArray($record['rules'], (string) ($record['source'] ?? 'rulegen'));
        } catch (UnexpectedValueException) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    public function buildRecord(string $revision): ?array
    {
        $record = Cache::get(self::BUILD_KEY_PREFIX.$revision);

        return is_array($record) ? $record : null;
    }

    /** @return array<string, mixed>|null */
    public function activeRecord(): ?array
    {
        $revision = Cache::get(self::ACTIVE_KEY);
        if (is_array($revision)) {
            return $revision;
        }

        return is_string($revision) ? $this->buildRecord($revision) : null;
    }

    public function previousRevision(): ?string
    {
        $revision = Cache::get(self::PREVIOUS_KEY);

        return is_string($revision) ? $revision : null;
    }

    /**
     * Store the verified signer under its build and point workers at it. The
     * build record carries how long the previous build was being rejected
     * before this one took over (time from rotation to recovery).
     */
    public function activate(OnlyfansRuleSet $rules, string $verified): void
    {
        $previous = Cache::get(self::ACTIVE_KEY);
        $firstRejection = is_string($previous) ? Cache::get(self::FIRST_REJECTION_KEY_PREFIX.$previous) : null;

        Cache::forever(self::BUILD_KEY_PREFIX.$rules->revision, [
            'rules' => $rules->toArray(),
            'source' => $rules->source,
            'mode' => $rules->mode,
            'verified' => $verified,
            'activated_at' => now()->toIso8601String(),
            'replaced' => is_string($previous) && $previous !== $rules->revision ? $previous : null,
            'recovery_seconds' => is_string($firstRejection)
                ? max(0, now()->getTimestamp() - Carbon::parse($firstRejection)->getTimestamp())
                : null,
        ]);

        if (is_string($previous) && $previous !== $rules->revision) {
            Cache::forever(self::PREVIOUS_KEY, $previous);
        }
        Cache::forever(self::ACTIVE_KEY, $rules->revision);

        $this->pruneBuilds($rules->revision);
    }

    /** @return list<string> newest first */
    public function builds(): array
    {
        $builds = Cache::get(self::BUILDS_KEY);

        return is_array($builds) ? array_values(array_filter($builds, 'is_string')) : [];
    }

    private function pruneBuilds(string $newest): void
    {
        $builds = array_values(array_unique([$newest, ...$this->builds()]));
        $keep = array_slice($builds, 0, self::KEEP_BUILDS);
        $protected = [Cache::get(self::ACTIVE_KEY), Cache::get(self::PREVIOUS_KEY)];

        foreach (array_slice($builds, self::KEEP_BUILDS) as $old) {
            if (in_array($old, $protected, true)) {
                $keep[] = $old;

                continue;
            }
            Cache::forget(self::BUILD_KEY_PREFIX.$old);
        }

        Cache::forever(self::BUILDS_KEY, $keep);
    }

    /** Rejections per build, and when the first one happened. */
    public function recordRejection(string $revision): void
    {
        Cache::add(self::REJECTIONS_KEY_PREFIX.$revision, 0, self::METRIC_TTL_SECONDS);
        Cache::increment(self::REJECTIONS_KEY_PREFIX.$revision);
        Cache::add(self::FIRST_REJECTION_KEY_PREFIX.$revision, now()->toIso8601String(), self::METRIC_TTL_SECONDS);
    }

    public function rejections(string $revision): int
    {
        return (int) Cache::get(self::REJECTIONS_KEY_PREFIX.$revision, 0);
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

    /** @return array<string, mixed>|null */
    public function canaryStatus(): ?array
    {
        $status = Cache::get(self::CANARY_KEY);

        return is_array($status) ? $status : null;
    }

    /** @param array<string, mixed> $status */
    public function recordCanary(array $status): void
    {
        Cache::forever(self::CANARY_KEY, $status + ['checked_at' => now()->toIso8601String()]);
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
