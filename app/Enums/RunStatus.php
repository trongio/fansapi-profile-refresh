<?php

namespace App\Enums;

/** Lifecycle of one logical refresh run. Cast on RefreshRun::status. */
enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case VerifiedUnchanged = 'verified_unchanged';
    case StaleIgnored = 'stale_ignored';
    case Failed = 'failed';
    case DeadLettered = 'dead_lettered';

    /** @return list<self> statuses that mean "this run will never do more work" */
    public static function terminal(): array
    {
        return [self::Succeeded, self::VerifiedUnchanged, self::StaleIgnored, self::Failed, self::DeadLettered];
    }

    /** @return list<self> a valid refresh actually landed */
    public static function committed(): array
    {
        return [self::Succeeded, self::VerifiedUnchanged];
    }

    /** @return list<self> still owns the profile's pending pointer */
    public static function active(): array
    {
        return [self::Queued, self::Running];
    }

    /** @return list<self> shown in the dead-letter view */
    public static function deadLetterable(): array
    {
        return [self::DeadLettered, self::Failed];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    public function isCommitted(): bool
    {
        return in_array($this, self::committed(), true);
    }

    /** Readable label for the UI. */
    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
