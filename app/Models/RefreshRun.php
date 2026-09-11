<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The durable logical unit of work.
 *
 * One row is the idempotency key, the metrics record, the recovery record and
 * the replay link. The queue job only carries its id.
 */
class RefreshRun extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_VERIFIED_UNCHANGED = 'verified_unchanged';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STALE_IGNORED = 'stale_ignored';
    public const STATUS_DEAD_LETTERED = 'dead_lettered';

    /** Statuses that mean "this run will never do any more work". */
    public const TERMINAL = [
        self::STATUS_SUCCEEDED,
        self::STATUS_VERIFIED_UNCHANGED,
        self::STATUS_STALE_IGNORED,
        self::STATUS_FAILED,
        self::STATUS_DEAD_LETTERED,
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enqueued_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RefreshAttempt::class);
    }

    public function replayOf(): BelongsTo
    {
        return $this->belongsTo(RefreshRun::class, 'replay_of_run_id');
    }

    public function replays(): HasMany
    {
        return $this->hasMany(RefreshRun::class, 'replay_of_run_id');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    public function isCommitted(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCEEDED, self::STATUS_VERIFIED_UNCHANGED], true);
    }

    /** Age measured from the ORIGINAL enqueue, not from the last release. */
    public function waitingSeconds(?\DateTimeInterface $now = null): int
    {
        return max(0, ($now ? $now->getTimestamp() : time()) - $this->enqueued_at->getTimestamp());
    }
}
