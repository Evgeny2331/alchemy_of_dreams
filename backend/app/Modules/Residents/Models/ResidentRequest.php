<?php

namespace App\Modules\Residents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentRequest extends Model
{
    public $timestamps = false;

    protected $table = 'resident_requests';

    protected $fillable = [
        'resident_id',
        'request_type',
        'cost_payload',
        'reward_payload',
        'status',
        'available_at',
        'expires_at',
        'completed_at',
        'created_at',
    ];

    protected $casts = [
        'cost_payload' => 'array',
        'reward_payload' => 'array',
        'available_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class, 'resident_id');
    }
}
