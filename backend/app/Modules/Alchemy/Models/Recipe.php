<?php

namespace App\Modules\Alchemy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends Model
{
    protected $table = 'recipes';

    protected $fillable = [
        'recipe_key',
        'category',
        'result_type',
        'result_key',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class, 'recipe_id');
    }
}
