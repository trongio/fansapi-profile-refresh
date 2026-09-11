<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;

/**
 * A refresh target. Holds the last ACCEPTED snapshot; a rejected response
 * must never move any column on this row.
 */
class Profile extends Model
{
    use HasFactory;
    use Searchable;

    protected $fillable = [
        'account_id', 'username', 'upstream_id', 'display_name',
        'likes', 'revision', 'snapshot', 'snapshot_source', 'snapshot_from_cache',
        'next_refresh_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'snapshot_from_cache' => 'boolean',
            'likes' => 'integer',
            'revision' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_failure_at' => 'immutable_datetime',
            'verified_unchanged_at' => 'immutable_datetime',
            'next_refresh_at' => 'immutable_datetime',
            'terminal_failed_at' => 'immutable_datetime',
        ];
    }

    /** Scout database engine: a deliberately small searchable field set. */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'upstream_id' => $this->upstream_id,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RefreshRun::class);
    }

    public function pendingRun(): BelongsTo
    {
        return $this->belongsTo(RefreshRun::class, 'pending_run_id');
    }

    /**
     * Schedulable rows only: nothing already pending, nothing terminally
     * failed, and due at or before $now. Matches profiles_due_idx.
     */
    public function scopeDue(Builder $query, \DateTimeInterface $now): Builder
    {
        return $query->whereNull('terminal_failed_at')
            ->whereNull('pending_run_id')
            ->where(fn (Builder $q) => $q->whereNull('next_refresh_at')->orWhere('next_refresh_at', '<=', $now));
    }
}
