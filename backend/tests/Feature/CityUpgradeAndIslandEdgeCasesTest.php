<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CityUpgradeAndIslandEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_island_unlock_order_and_building_max_level_guard(): void
    {
        $player = Player::create(['telegram_id' => 7401, 'first_name' => 'Architect']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '2000.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $token = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Sky Archipelago',
            'economy_calculated_at' => $t0,
        ]);

        $island0 = Island::create(['city_id' => $city->id, 'island_index' => 0, 'is_unlocked' => true]);
        $island1 = Island::create(['city_id' => $city->id, 'island_index' => 1, 'is_unlocked' => false]);
        $island2 = Island::create(['city_id' => $city->id, 'island_index' => 2, 'is_unlocked' => false]);

        // Пропуск острова 1 должен быть отклонён
        $this->withToken($token)
            ->postJson("/api/v1/city/islands/{$island2->id}/unlock")
            ->assertStatus(422);

        // Последовательное открытие островов 1 и 2 проходит успешно
        $this->withToken($token)
            ->postJson("/api/v1/city/islands/{$island1->id}/unlock")
            ->assertStatus(200)
            ->assertJsonPath('data.island.is_unlocked', true);

        $this->withToken($token)
            ->postJson("/api/v1/city/islands/{$island2->id}/unlock")
            ->assertStatus(200)
            ->assertJsonPath('data.island.is_unlocked', true);

        $catalog = BuildingCatalog::create([
            'building_key' => 'spire_of_dreams',
            'category' => 'functional',
            'display_name' => 'Шпиль Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
        ]);

        $building = Building::create([
            'city_id' => $city->id,
            'island_id' => $island0->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'spire_of_dreams',
            'level' => 1,
            'position_x' => 1,
            'position_y' => 1,
            'status' => 'active',
            'built_at' => $t0,
        ]);

        // Апгрейд с 1 до 2 и с 2 до 3 проходит, а с 3 до 4 отклоняется (422)
        $this->withToken($token)
            ->postJson("/api/v1/city/buildings/{$building->id}/upgrade")
            ->assertStatus(200)
            ->assertJsonPath('data.building.level', 2);

        $this->withToken($token)
            ->postJson("/api/v1/city/buildings/{$building->id}/upgrade")
            ->assertStatus(200)
            ->assertJsonPath('data.building.level', 3);

        $this->withToken($token)
            ->postJson("/api/v1/city/buildings/{$building->id}/upgrade")
            ->assertStatus(422);
    }
}
