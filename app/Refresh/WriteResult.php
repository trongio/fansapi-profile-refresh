<?php

namespace App\Refresh;

/** What the writer actually did, so the job can report it without guessing. */
final class WriteResult
{
    private function __construct(
        public readonly string $category,
        public readonly string $runStatus,
        public readonly string $message,
    ) {}

    public static function applied(): self
    {
        return new self(Outcome::ACCEPTED, \App\Models\RefreshRun::STATUS_SUCCEEDED, 'data updated');
    }

    public static function verifiedUnchanged(): self
    {
        return new self(Outcome::VERIFIED_UNCHANGED, \App\Models\RefreshRun::STATUS_VERIFIED_UNCHANGED,
            'same revision, identical content');
    }

    public static function staleIgnored(int $received, int $accepted): self
    {
        return new self(Outcome::STALE_IGNORED, \App\Models\RefreshRun::STATUS_STALE_IGNORED,
            "revision {$received} is older than accepted {$accepted}");
    }

    public static function alreadyCompleted(string $status): self
    {
        return new self('already_completed', $status, 'run was already committed; replay made no change');
    }
}
