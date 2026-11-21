<?php

namespace App\Modules\Alchemy\Models;

use App\Modules\Auth\Models\Player;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRecipeArchive extends Model
{
    protected $table = 'player_recipe_archive';

    public $timestamps = false;

    protected $fillable = [
        'player_id',
        'recipe_id',
        'unlocked_at',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
    ];

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }
}
