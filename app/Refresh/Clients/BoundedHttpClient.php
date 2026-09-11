<?php

namespace App\Refresh\Clients;

use App\Refresh\Outcome;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Shared transport for every profile source.
 *
 * The body is streamed and counted while it is still arriving, so a body with
 * no Content-Length, a chunked body or a gzip bomb is cut off before it is
 * ever held in full. Checking size after ->json() would already be too late.
 * The HTTP client itself never retries: the queue owns retries, and layering
 * both would multiply the real upstream request count.
 */
abstract class BoundedHttpClient
{
    private const CHUNK = 8192;

    private const JSON_DEPTH = 32;

    /** @param array<string,string> $headers */
    protected function get(string $url, array $headers = []): ClientResult
    {
        $config = config('fansapi.http');
        $startedAt = hrtime(true);
        $elapsed = fn (): int => (int) ((hrtime(true) - $startedAt) / 1_000_000);

        try {
            $response = Http::withHeaders($headers + ['Accept' => 'application/json'])
                ->withOptions([
                    'stream' => true,
                    'http_errors' => false,
                    'connect_timeout' => $config['connect_timeout'],
                    'timeout' => $config['timeout'],
                ])
                ->get($url);
        } catch (ConnectionException $e) {
            $category = str_contains(strtolower($e->getMessage()), 'timed out')
                ? Outcome::TIMEOUT
                : Outcome::NETWORK_ERROR;

            return new ClientResult($category, null, null, $elapsed(), message: $this->summarise($e->getMessage()));
        }

        $status = $response->status();
        $stream = $response->toPsrResponse()->getBody();

        try {
            $raw = $this->readBounded($stream, (int) $config['max_body_bytes']);
        } catch (BodyTooLarge $e) {
            return new ClientResult(Outcome::BODY_TOO_LARGE, $status, null, $elapsed(), message: $e->getMessage());
        }

        if ($status === 429) {
            return new ClientResult(
                Outcome::THROTTLED,
                $status,
                null,
                $elapsed(),
                retryAfterSeconds: $this->retryAfter($response->header('Retry-After')),
            );
        }

        if ($status >= 500) {
            return new ClientResult(Outcome::SERVER_ERROR, $status, null, $elapsed(),
                message: $raw === '' ? 'empty body' : 'upstream error');
        }

        if ($status === 401) {
            return new ClientResult(Outcome::INVALID_CREDENTIALS, $status, null, $elapsed());
        }

        if ($status === 403) {
            // Not automatically "profile deleted": category is inspected, not assumed.
            return new ClientResult(Outcome::FORBIDDEN, $status, null, $elapsed());
        }

        if ($status === 404) {
            return new ClientResult(Outcome::PROFILE_MISSING, $status, null, $elapsed());
        }

        if ($status < 200 || $status >= 300) {
            return new ClientResult(Outcome::UNEXPECTED_STATUS, $status, null, $elapsed());
        }

        // Decode exactly once; no raw/array/object copies are kept afterwards.
        try {
            $decoded = json_decode($raw, true, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return new ClientResult(Outcome::SCHEMA_FAILURE, $status, null, $elapsed(),
                message: $raw === '' ? 'empty body' : 'malformed JSON');
        }
        unset($raw);

        if (! is_array($decoded)) {
            return new ClientResult(Outcome::SCHEMA_FAILURE, $status, null, $elapsed(), message: 'body is not an object');
        }

        return new ClientResult('ok', $status, $decoded, $elapsed());
    }

    /** @throws BodyTooLarge */
    private function readBounded(StreamInterface $stream, int $limit): string
    {
        $buffer = '';

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) > $limit) {
                    throw new BodyTooLarge("response body exceeded {$limit} bytes");
                }
            }
        } finally {
            // Close on every path, including the oversize throw.
            $stream->close();
        }

        return $buffer;
    }

    /** Seconds or an HTTP-date. Never shortened, never ignored. */
    private function retryAfter(?string $header): ?int
    {
        $header = trim((string) $header);
        if ($header === '') {
            return null;
        }

        if (preg_match('/\A[0-9]+\z/', $header) === 1) {
            return (int) $header;
        }

        $when = strtotime($header);
        if ($when === false) {
            return null;
        }

        return max(0, $when - time());
    }

    private function summarise(string $message): string
    {
        // Never log a full upstream error body or anything credential shaped.
        return substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 120);
    }
}
