<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSession extends Model
{
    protected $fillable = [
        'player_id',
        'token_hash',
        'expires_at',
        'revoked_at',
        'last_used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
