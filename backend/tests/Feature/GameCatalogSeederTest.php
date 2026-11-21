<?php

namespace Tests\Feature;

use Database\Seeders\GameCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GameCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_catalog_seeder_is_idempotent(): void
    {
        $this->seed(GameCatalogSeeder::class);
        $this->seed(GameCatalogSeeder::class);

        $this->assertSame(3, DB::table('building_catalog')->count());
        $this->assertSame(7, DB::table('building_level_configs')->count());
        $this->assertSame(2, DB::table('recipes')->count());
        $this->assertSame(4, DB::table('recipe_components')->count());
    }
}
