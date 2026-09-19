<?php

namespace App\Refresh\Clients;

use App\Models\Profile;
use App\Refresh\Outcome;

/**
 * Direct anonymous access to the public profile endpoint, signed the way the
 * logged-out web client signs. The signer is extracted locally from the
 * current web build (OnlyfansRuleRefresher):
 *  - constants mode: PHP signs by itself with OnlyfansSigner;
 *  - delegated mode (the build changed the formula): the build's own
 *    extracted function signs, via the isolated rulegen service.
 * No browser, account, cookies, x-hash, x-of-rev or user-id header is sent.
 *
 * A signature rejection keeps the active signer (it is last-known-good),
 * counts the rejection against its build, drops only the cheap x-bc value
 * and asks the scheduler for one refresh.
 */
class OnlyfansDirectClient extends BoundedHttpClient implements ProfileClient
{
    public function __construct(
        private readonly OnlyfansRules $rules,
        private readonly OnlyfansRulegenClient $rulegen,
    ) {}

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
            $this->rules->recordRejection($rules->revision);
            $this->rules->forgetBrowserCode();
            $this->rules->requestRefresh("signature rejected for {$rules->revision}");
        }

        return $result;
    }

    /** One signed GET with the given signer. Also used by the canary. */
    public function request(OnlyfansRuleSet $rules, string $path): ClientResult
    {
        $browserCode = $this->rules->browserCode();
        if ($browserCode === null) {
            return new ClientResult(Outcome::NETWORK_ERROR, null, null, 0, message: 'x-bc endpoint unavailable');
        }

        if ($rules->delegated()) {
            try {
                $signed = $this->rulegen->sign($rules->revision, $path);
            } catch (RulegenFailed $e) {
                // rulegen restarted and lost the loaded build: an extraction
                // reloads it. Retryable, no upstream request was made.
                if ($e->reason === 'NOT_LOADED') {
                    $this->rules->requestRefresh("delegated signer {$rules->revision} not loaded");
                }

                return new ClientResult(Outcome::NETWORK_ERROR, null, null, 0, message: "delegated signer unavailable: {$e->reason}");
            }
            $signature = ['app-token' => $rules->appToken, 'sign' => $signed['sign'], 'time' => $signed['time']];
        } else {
            $signature = OnlyfansSigner::headers($rules->signatureRules(), $path);
        }

        $headers = $signature + [
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
