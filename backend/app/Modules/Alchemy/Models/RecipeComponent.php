<?php

namespace App\Modules\Alchemy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeComponent extends Model
{
    protected $table = 'recipe_components';

    public $timestamps = false;

    protected $fillable = [
        'recipe_id',
        'component_type',
        'component_key',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:6',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }
}
