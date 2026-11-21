<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GameCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now('UTC');

        $buildings = [
            [
                'building_key' => 'fog_condenser',
                'category' => 'functional',
                'display_name' => 'Конденсатор Тумана',
                'is_unique' => false,
                'is_story' => false,
                'supports_upgrade' => true,
                'footprint_width' => 2,
                'footprint_height' => 2,
                'is_active' => true,
                'metadata' => json_encode(['role' => 'fog_generator'], JSON_THROW_ON_ERROR),
                'levels' => [
                    1 => ['fog_per_hour' => '25.000000', 'storage_capacity' => '200.000000', 'build_time_seconds' => 60],
                    2 => ['fog_per_hour' => '55.000000', 'storage_capacity' => '450.000000', 'build_time_seconds' => 180],
                    3 => ['fog_per_hour' => '100.000000', 'storage_capacity' => '900.000000', 'build_time_seconds' => 360],
                ],
            ],
            [
                'building_key' => 'dream_loom',
                'category' => 'functional',
                'display_name' => 'Ткацкий Стан Грёз',
                'is_unique' => false,
                'is_story' => false,
                'supports_upgrade' => true,
                'footprint_width' => 2,
                'footprint_height' => 2,
                'is_active' => true,
                'metadata' => json_encode(['role' => 'resident_hub'], JSON_THROW_ON_ERROR),
                'levels' => [
                    1 => ['fog_per_hour' => '10.000000', 'storage_capacity' => '120.000000', 'build_time_seconds' => 45],
                    2 => ['fog_per_hour' => '22.000000', 'storage_capacity' => '260.000000', 'build_time_seconds' => 150],
                    3 => ['fog_per_hour' => '40.000000', 'storage_capacity' => '500.000000', 'build_time_seconds' => 300],
                ],
            ],
            [
                'building_key' => 'lantern_of_silence',
                'category' => 'decorative',
                'display_name' => 'Фонарь Тишины',
                'is_unique' => false,
                'is_story' => false,
                'supports_upgrade' => false,
                'footprint_width' => 1,
                'footprint_height' => 1,
                'is_active' => true,
                'metadata' => json_encode(['role' => 'decor'], JSON_THROW_ON_ERROR),
                'levels' => [
                    1 => ['fog_per_hour' => '5.000000', 'storage_capacity' => '50.000000', 'build_time_seconds' => 15],
                ],
            ],
        ];

        foreach ($buildings as $b) {
            $levels = $b['levels'];
            unset($b['levels']);

            DB::table('building_catalog')->updateOrInsert(
                ['building_key' => $b['building_key']],
                array_merge($b, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );

            $catalogId = (int) DB::table('building_catalog')
                ->where('building_key', $b['building_key'])
                ->value('id');

            foreach ($levels as $lvl => $cfg) {
                DB::table('building_level_configs')->updateOrInsert(
                    [
                        'building_catalog_id' => $catalogId,
                        'level' => $lvl,
                    ],
                    [
                        'fog_per_hour' => $cfg['fog_per_hour'],
                        'storage_capacity' => $cfg['storage_capacity'],
                        'build_time_seconds' => $cfg['build_time_seconds'],
                        'config' => json_encode(['tier' => $lvl], JSON_THROW_ON_ERROR),
                    ]
                );
            }
        }

        $recipes = [
            [
                'recipe_key' => 'distilled_memory',
                'category' => 'functional',
                'result_type' => 'resource',
                'result_key' => 'memories',
                'metadata' => json_encode(['tier' => 1], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'components' => [
                    ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '25.000000'],
                    ['component_type' => 'resource', 'component_key' => 'silence', 'quantity' => '5.000000'],
                ],
            ],
            [
                'recipe_key' => 'whispering_metaphor',
                'category' => 'secret',
                'result_type' => 'resource',
                'result_key' => 'metaphors',
                'metadata' => json_encode(['tier' => 2], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'components' => [
                    ['component_type' => 'resource', 'component_key' => 'memories', 'quantity' => '10.000000'],
                    ['component_type' => 'resource', 'component_key' => 'nightmare_shards', 'quantity' => '3.000000'],
                ],
            ],
        ];

        foreach ($recipes as $r) {
            $components = $r['components'];
            unset($r['components']);

            DB::table('recipes')->updateOrInsert(
                ['recipe_key' => $r['recipe_key']],
                array_merge($r, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );

            $recipeId = (int) DB::table('recipes')
                ->where('recipe_key', $r['recipe_key'])
                ->value('id');

            foreach ($components as $comp) {
                DB::table('recipe_components')->updateOrInsert(
                    [
                        'recipe_id' => $recipeId,
                        'component_type' => $comp['component_type'],
                        'component_key' => $comp['component_key'],
                    ],
                    [
                        'quantity' => $comp['quantity'],
                    ]
                );
            }
        }
    }
}
