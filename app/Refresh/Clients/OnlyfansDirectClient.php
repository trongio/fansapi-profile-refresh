<?php

namespace App\Refresh\Clients;

use App\Models\Profile;
use App\Refresh\Outcome;

/**
 * Direct anonymous access to the public profile endpoint, signed in PHP the
 * way the logged-out web client signs. The signing rules are extracted
 * locally from the current web build (OnlyfansRuleRefresher); no browser,
 * account, cookies, x-hash, x-of-rev or user-id header is sent.
 *
 * A signature rejection keeps the active rules (they are last-known-good),
 * drops only the cheap x-bc value and asks the scheduler for one refresh.
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
        if ($rules === null) {
            $this->rules->requestRefresh('no active signing rules');

            return new ClientResult(Outcome::SIGNATURE_REJECTED, null, null, 0,
                message: 'no active signing rules yet', signingRevision: 'none');
        }

        $result = $this->request($rules, '/api2/v2/users/'.rawurlencode($profile->upstream_id ?: $profile->username));

        if ($result->category === Outcome::SIGNATURE_REJECTED) {
            $this->rules->forgetBrowserCode();
            $this->rules->requestRefresh("signature rejected for {$rules->revision}");
        }

        return $result;
    }

    /** One signed GET with the given rules. Also used by the activation canary. */
    public function request(OnlyfansRuleSet $rules, string $path): ClientResult
    {
        $browserCode = $this->rules->browserCode();
        if ($browserCode === null) {
            return new ClientResult(Outcome::NETWORK_ERROR, null, null, 0, message: 'x-bc endpoint unavailable');
        }

        $headers = OnlyfansSigner::headers($rules->signatureRules(), $path) + [
            'x-bc' => $browserCode,
            'User-Agent' => (string) config('fansapi.onlyfans.user_agent'),
            'Accept' => 'application/json, text/plain, */*',
            'Referer' => 'https://onlyfans.com/',
        ];
        // The user id (0) is part of the signed string, but a logged-out
        // browser does not send it as a header.
        unset($headers['user-id']);

        $result = $this->get(rtrim((string) config('fansapi.onlyfans.base_url'), '/').$path, $headers);

        if ($result->status === 400 && str_contains(strtolower((string) $result->message), 'refresh the page')) {
            return new ClientResult(
                Outcome::SIGNATURE_REJECTED,
                400,
                null,
                $result->durationMs,
                message: "signature rejected for signing revision {$rules->revision}",
                signingRevision: $rules->revision,
            );
        }

        return $result;
    }
}
