<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One upstream HTTP request: what it cost and what category it landed in. */
class RefreshAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'refresh_run_id', 'attempt_no', 'http_status', 'category',
        'duration_ms', 'retry_after_seconds', 'received_revision', 'message',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(RefreshRun::class, 'refresh_run_id');
    }
}
