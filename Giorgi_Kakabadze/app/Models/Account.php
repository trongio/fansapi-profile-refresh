<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Upstream access context: credentials scope, shared workspace quota and the
 * Horizon queue reserved for it. One account owns many refresh targets.
 */
class Account extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'label', 'queue', 'source', 'workspace', 'cooldown_until'];

    protected function casts(): array
    {
        return ['cooldown_until' => 'immutable_datetime'];
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(Profile::class);
    }
}
