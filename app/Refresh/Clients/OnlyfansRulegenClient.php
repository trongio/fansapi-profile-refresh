<?php

namespace App\Refresh\Clients;

use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Talks to the internal rulegen service (services/rulegen). PHP never passes
 * a URL or code and never executes JavaScript:
 *  - extract(): no inputs; the answer is untrusted data that is validated,
 *    proved and canaried before anything signs with it.
 *  - sign(): delegated builds only; a build id and a profile path, answered
 *    by that build's own extracted sign function.
 */
class OnlyfansRulegenClient
{
    /**
     * @return array<string, mixed>
     *
     * @throws RulegenFailed
     */
    public function extract(): array
    {
        $config = config('fansapi.onlyfans');
        $limit = (int) $config['rulegen_max_body_bytes'];

        try {
            $response = Http::connectTimeout(3)
                ->timeout((int) $config['rulegen_timeout_seconds'])
                ->withOptions(['stream' => true, 'http_errors' => false, 'allow_redirects' => false])
                ->withBody('', 'application/json')
                ->post(rtrim((string) $config['rulegen_url'], '/').'/v1/extract');
        } catch (Throwable $e) {
            throw new RulegenFailed('RULEGEN_UNREACHABLE', $e->getMessage());
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        try {
            while (! $stream->eof()) {
                $body .= $stream->read(8192);
                if (strlen($body) > $limit) {
                    throw new RulegenFailed('RULEGEN_BODY_TOO_LARGE', "rulegen answer exceeded {$limit} bytes");
                }
            }
        } finally {
            $stream->close();
        }

        try {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RulegenFailed('RULEGEN_MALFORMED', "HTTP {$response->status()} with a non-JSON body");
        }

        if (! is_array($payload)) {
            throw new RulegenFailed('RULEGEN_MALFORMED', 'rulegen answer is not an object');
        }

        if ($response->status() !== 200 || ($payload['ok'] ?? false) !== true) {
            $code = is_string($payload['error'] ?? null) && preg_match('/\A[A-Z_]{1,40}\z/', $payload['error']) === 1
                ? $payload['error']
                : 'RULEGEN_FAILED';

            throw new RulegenFailed($code, is_string($payload['detail'] ?? null) ? $payload['detail'] : "HTTP {$response->status()}");
        }

        return $payload;
    }

    /**
     * One signature from a delegated build's extracted function.
     *
     * @return array{time: string, sign: string}
     *
     * @throws RulegenFailed NOT_LOADED when rulegen no longer holds the build
     */
    public function sign(string $revision, string $path): array
    {
        $config = config('fansapi.onlyfans');

        try {
            $response = Http::connectTimeout(2)
                ->timeout((int) $config['rulegen_sign_timeout_seconds'])
                ->withOptions(['http_errors' => false, 'allow_redirects' => false])
                ->asJson()
                ->post(rtrim((string) $config['rulegen_url'], '/').'/v1/sign', ['revision' => $revision, 'path' => $path]);
        } catch (Throwable $e) {
            throw new RulegenFailed('RULEGEN_UNREACHABLE', $e->getMessage());
        }

        $body = $response->body();
        $payload = strlen($body) <= 4096 ? json_decode($body, true) : null;
        if (! is_array($payload)) {
            throw new RulegenFailed('RULEGEN_MALFORMED', "sign answered HTTP {$response->status()}");
        }

        if ($response->status() !== 200 || ($payload['ok'] ?? false) !== true) {
            $code = is_string($payload['error'] ?? null) && preg_match('/\A[A-Z_]{1,40}\z/', $payload['error']) === 1
                ? $payload['error']
                : 'RULEGEN_FAILED';

            throw new RulegenFailed($code, "sign answered HTTP {$response->status()}");
        }

        $time = $payload['time'] ?? null;
        $sign = $payload['sign'] ?? null;
        if (! is_string($time) || preg_match('/\A[0-9]{13}\z/', $time) !== 1
            || ! is_string($sign) || preg_match('/\A[\x21-\x7e]{1,256}\z/', $sign) !== 1
            || ($payload['revision'] ?? null) !== $revision) {
            throw new RulegenFailed('RULEGEN_MALFORMED', 'sign answer has the wrong shape');
        }

        return ['time' => $time, 'sign' => $sign];
    }
}
