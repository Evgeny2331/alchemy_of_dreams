<?php

namespace App\Modules\Expedition\Models;

use App\Modules\Residents\Models\Resident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpeditionResident extends Model
{
    protected $table = 'expedition_residents';

    protected $fillable = [
        'expedition_id',
        'resident_id',
        'energy_spent',
    ];

    protected $casts = [
        'energy_spent' => 'decimal:2',
    ];

    public function expedition(): BelongsTo
    {
        return $this->belongsTo(Expedition::class, 'expedition_id');
    }

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id');
    }
}
