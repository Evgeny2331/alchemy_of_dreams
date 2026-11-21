<?php

namespace App\Modules\City\Models;

use Illuminate\Database\Eloquent\Model;

class BuildingCatalog extends Model
{
    protected $table = 'building_catalog';

    protected $fillable = [
        'building_key',
        'category',
        'display_name',
        'is_unique',
        'is_story',
        'supports_upgrade',
        'footprint_width',
        'footprint_height',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_unique' => 'boolean',
        'is_story' => 'boolean',
        'supports_upgrade' => 'boolean',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
}
