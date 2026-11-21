<?php

namespace App\Modules\City\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Building extends Model
{
    protected $table = 'buildings';

    protected $fillable = [
        'city_id',
        'island_id',
        'building_catalog_id',
        'building_type',
        'level',
        'position_x',
        'position_y',
        'status',
        'built_at',
        'demolished_at',
    ];

    protected $casts = [
        'built_at' => 'datetime',
        'demolished_at' => 'datetime',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function island(): BelongsTo
    {
        return $this->belongsTo(Island::class);
    }
}
