<?php

namespace App\Modules\Economy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Auth\Models\Player;

class PlayerResource extends Model
{
    protected $table = 'player_resources';

    protected $fillable = [
        'player_id',
        'resource_type',
        'balance',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
