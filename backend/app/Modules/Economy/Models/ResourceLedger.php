<?php

namespace App\Modules\Economy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Auth\Models\Player;

class ResourceLedger extends Model
{
    protected $table = 'resource_ledgers';

    protected $fillable = [
        'player_id',
        'resource_type',
        'amount',
        'operation_type',
        'idempotency_key',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
