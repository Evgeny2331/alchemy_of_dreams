<?php

namespace App\Modules\City\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Auth\Models\Player;

class City extends Model
{
    protected $table = 'cities';

    protected $fillable = [
        'player_id',
        'name',
        'economy_calculated_at',
    ];

    protected $casts = [
        'economy_calculated_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function islands(): HasMany
    {
        return $this->hasMany(Island::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
