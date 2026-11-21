<?php

namespace App\Modules\Auth\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    protected $table = 'players';

    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    // Явно указываем подключение для избежания рассинхронизации в тестах
    public function getConnectionName()
    {
        return config('database.default');
    }

    public function city(): HasOne
    {
        return $this->hasOne(\App\Modules\City\Models\City::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(\App\Modules\Economy\Models\PlayerResource::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PlayerSession::class);
    }
}
