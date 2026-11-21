<?php

namespace App\Modules\WorldPhase\Models;

use Illuminate\Database\Eloquent\Model;

class WorldPhase extends Model
{
    protected $table = 'world_phases';

    protected $fillable = [
        'phase_name',
        'multiplier_fog',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'multiplier_fog' => 'decimal:6',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
