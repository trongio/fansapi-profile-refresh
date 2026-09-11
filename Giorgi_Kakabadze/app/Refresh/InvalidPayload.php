<?php

namespace App\Refresh;

use RuntimeException;

/** A 2xx response we refuse to accept. Carries the category for logs + UI. */
class InvalidPayload extends RuntimeException
{
    public function __construct(public readonly string $category, string $message)
    {
        parent::__construct($message);
    }

    public static function schema(string $message): self
    {
        return new self(Outcome::SCHEMA_FAILURE, $message);
    }

    public static function identity(string $message): self
    {
        return new self(Outcome::IDENTITY_MISMATCH, $message);
    }
}
