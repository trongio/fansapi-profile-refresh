<?php

namespace App\Models;

use App\Enums\RunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The durable logical unit of work.
 *
 * One row is the idempotency key, the metrics record, the recovery record and
 * the replay link. The queue job only carries its id.
 */
class RefreshRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
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
        return $this->status->isTerminal();
    }

    public function isCommitted(): bool
    {
        return $this->status->isCommitted();
    }

    /** Age measured from the ORIGINAL enqueue, not from the last release. */
    public function waitingSeconds(?\DateTimeInterface $now = null): int
    {
        return max(0, ($now ? $now->getTimestamp() : time()) - $this->enqueued_at->getTimestamp());
    }

    /**
     * Called when this run finishes and it was a replay. The original keeps
     * its dead-lettered status - the audit trail is the point - but is marked
     * resolved, which is different from "a replay was queued".
     */
    public function resolveReplayedOriginal(): void
    {
        if ($this->replay_of_run_id === null) {
            return;
        }

        static::query()->whereKey($this->replay_of_run_id)->update([
            'resolved_at' => Carbon::now(),
            'outcome_message' => "resolved by replay run {$this->id} ({$this->status->value})",
        ]);
    }
}
