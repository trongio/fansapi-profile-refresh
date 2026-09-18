<?php

namespace App\Refresh\Clients;

use App\Models\Profile;
use App\Refresh\Outcome;

/**
 * Direct, anonymous fetch from onlyfans.com, the way the web client does it for
 * a logged-out visitor: signed headers, user id 0, a client-generated device
 * id, no account and no cookie jar. No browser is involved, so the memory cost
 * is the same as any other HTTP call in this app, and it scales the same way.
 *
 * The public profile endpoint returns the profile object itself (not wrapped in
 * "data"); the normalizer handles source "onlyfans" accordingly.
 *
 * Status today: OnlyFans answers a signed anonymous request with HTTP 400 and
 * {"error":{"code":401,"message":"Please refresh the page"}} when the signing
 * rules lag the current web build (the browser also sends x-of-rev and x-hash).
 * See evidence/direct-route-diagnosis.md. That outcome is surfaced as
 * SIGNATURE_REJECTED rather than crashing, and the managed provider adapter is
 * the fallback that returns data today.
 */
class OnlyfansDirectClient extends BoundedHttpClient implements ProfileClient
{
    public function __construct(private readonly OnlyfansRules $rules) {}

    public function source(): string
    {
        return 'onlyfans';
    }

    public function fetch(Profile $profile): ClientResult
    {
        $rules = $this->rules->current();
        if (! isset($rules['static_param'])) {
            return new ClientResult(Outcome::SIGNATURE_REJECTED, null, null, 0, message: 'no signing rules available');
        }

        $path = '/api2/v2/users/'.rawurlencode($profile->upstream_id ?: $profile->username);

        $result = $this->get(
            rtrim((string) config('fansapi.onlyfans.base_url'), '/').$path,
            OnlyfansSigner::headers($rules, $path) + [
                'x-bc' => $this->rules->deviceId(),
                'User-Agent' => (string) config('fansapi.onlyfans.user_agent'),
                'Accept' => 'application/json, text/plain, */*',
                'Referer' => 'https://onlyfans.com/',
            ],
        );

        // A stale or bad signature comes back as HTTP 400 carrying a 401 in the
        // body. Drop the cached rules so the next attempt refetches them.
        if ($result->status === 400 && str_contains((string) $result->message, 'refresh the page')) {
            $this->rules->forget();

            return new ClientResult(Outcome::SIGNATURE_REJECTED, 400, null, $result->durationMs,
                message: 'signature rejected; signing rules stale (source '.($rules['_source'] ?? '?').')');
        }

        return $result;
    }
}
