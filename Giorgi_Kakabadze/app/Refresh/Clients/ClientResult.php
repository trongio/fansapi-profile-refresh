<?php

namespace App\Refresh\Clients;

use App\Refresh\Outcome;

/** One transport-level outcome. No business rules live here. */
final class ClientResult
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        public readonly string $category,
        public readonly ?int $status,
        public readonly ?array $body,
        public readonly int $durationMs,
        public readonly ?int $retryAfterSeconds = null,
        public readonly ?string $message = null,
    ) {}

    public function ok(): bool
    {
        return $this->category === 'ok';
    }

    public function retryable(): bool
    {
        return Outcome::isRetryable($this->category);
    }
}
