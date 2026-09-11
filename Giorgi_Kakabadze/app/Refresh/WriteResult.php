<?php

namespace App\Refresh;

use App\Enums\RunStatus;

/** What the writer actually did, so the job can report it without guessing. */
final class WriteResult
{
    private function __construct(
        public readonly string $category,
        public readonly RunStatus $runStatus,
        public readonly string $message,
    ) {}

    public static function applied(): self
    {
        return new self(Outcome::ACCEPTED, RunStatus::Succeeded, 'data updated');
    }

    public static function verifiedUnchanged(): self
    {
        return new self(Outcome::VERIFIED_UNCHANGED, RunStatus::VerifiedUnchanged,
            'same revision, identical content');
    }

    public static function staleIgnored(int $received, int $accepted): self
    {
        return new self(Outcome::STALE_IGNORED, RunStatus::StaleIgnored,
            "revision {$received} is older than accepted {$accepted}");
    }

    public static function alreadyCompleted(RunStatus $status): self
    {
        return new self('already_completed', $status, 'run was already committed; replay made no change');
    }
}
