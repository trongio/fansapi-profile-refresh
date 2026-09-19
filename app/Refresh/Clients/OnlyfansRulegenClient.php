<?php

namespace App\Refresh\Clients;

use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Talks to the internal rulegen service (services/rulegen). One fixed
 * operation, no inputs: PHP never passes a URL, revision or code, and never
 * executes JavaScript. The answer is untrusted data that OnlyfansRules
 * validates and proves before anything signs with it.
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
}
