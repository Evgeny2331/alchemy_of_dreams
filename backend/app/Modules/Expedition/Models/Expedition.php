<?php

namespace App\Modules\Expedition\Models;

use App\Modules\City\Models\City;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expedition extends Model
{
    protected $table = 'expeditions';

    protected $fillable = [
        'city_id',
        'expedition_type',
        'status',
        'started_at',
        'ends_at',
        'completed_at',
        'claimed_at',
        'reward_payload',
        'outcome_seed',
        'balance_version',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
        'completed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'reward_payload' => 'array',
        'outcome_seed' => 'integer',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ExpeditionResident::class, 'expedition_id');
    }
}
