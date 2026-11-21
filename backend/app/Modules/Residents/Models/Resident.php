<?php

namespace App\Modules\Residents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Building;

class Resident extends Model
{
    protected $table = 'residents';

    protected $fillable = [
        'city_id',
        'archetype',
        'name',
        'trust_level',
        'energy',
        'status',
        'assigned_building_id',
    ];

    protected $casts = [
        'trust_level' => 'integer',
        'energy' => 'decimal:2',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function assignedBuilding(): BelongsTo
    {
        return $this->belongsTo(Building::class, 'assigned_building_id');
    }
}
