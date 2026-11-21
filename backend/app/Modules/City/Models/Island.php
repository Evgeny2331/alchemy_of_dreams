<?php

namespace App\Modules\City\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Island extends Model
{
    protected $table = 'islands';

    protected $fillable = [
        'city_id',
        'island_index',
        'is_unlocked',
    ];

    protected $casts = [
        'is_unlocked' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }
}
