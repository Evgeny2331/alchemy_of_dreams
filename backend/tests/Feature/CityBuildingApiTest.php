<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CityBuildingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_upgrade_demolish_footprint_collision_and_cost_progression_via_api(): void
    {
        $player = Player::create(['telegram_id' => 6201, 'first_name' => 'Builder']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '1000.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Sky Citadels',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        BuildingCatalog::create([
            'building_key' => 'mist_harvester',
            'category' => 'functional',
            'display_name' => 'Сборщик Тумана',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        BuildingCatalog::create([
            'building_key' => 'monument_of_echoes',
            'category' => 'decorative',
            'display_name' => 'Монумент Отголосков',
            'is_unique' => true,
            'is_story' => false,
            'supports_upgrade' => false,
            'footprint_width' => 1,
            'footprint_height' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);

        // Первая постройка 2x2 на (1, 1) -> N = 1, стоимость 100.000000 fog
        $build1 = $this->withToken($plainToken)->postJson('/api/v1/city/buildings', [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'mist_harvester',
            'position_x' => 1,
            'position_y' => 1,
            'now' => $t0->toIso8601String(),
        ]);
        $build1->assertStatus(201)
            ->assertJsonPath('data.building.status', 'active')
            ->assertJsonPath('data.building.level', 1)
            ->assertJsonPath('data.cost_info.historical_count_n', 1)
            ->assertJsonPath('data.cost_info.cost.fog', '100.000000');

        // Пересечение по внутреннему футпринту (2, 2) -> 422
        $this->withToken($plainToken)->postJson('/api/v1/city/buildings', [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'monument_of_echoes',
            'position_x' => 2,
            'position_y' => 2,
            'now' => $t0->toIso8601String(),
        ])->assertStatus(422);

        // Смежная свободная клетка (3, 1) не пересекает x:[1..2] -> 201
        $this->withToken($plainToken)->postJson('/api/v1/city/buildings', [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'monument_of_echoes',
            'position_x' => 3,
            'position_y' => 1,
            'now' => $t0->toIso8601String(),
        ])->assertStatus(201);

        $buildingId = (int) $build1->json('data.building.id');

        // Улучшение уровня 1 -> 2
        $upgradeResp = $this->withToken($plainToken)->postJson("/api/v1/city/buildings/{$buildingId}/upgrade");
        $upgradeResp->assertStatus(200)
            ->assertJsonPath('data.building.level', 2)
            ->assertJsonPath('data.upgrade_cost.target_level', 2);

        // Снос первого здания
        $demolish1 = $this->withToken($plainToken)->postJson("/api/v1/city/buildings/{$buildingId}/demolish", [
            'now' => $t0->addMinutes(15)->toIso8601String(),
        ]);
        $demolish1->assertStatus(200)
            ->assertJsonPath('data.status', 'demolished');

        // Вторая постройка на освободившихся координатах: N = 2, стоимость 115.000000
        $build2 = $this->withToken($plainToken)->postJson('/api/v1/city/buildings', [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'mist_harvester',
            'position_x' => 1,
            'position_y' => 1,
            'now' => $t0->addMinutes(20)->toIso8601String(),
        ]);
        $build2->assertStatus(201)
            ->assertJsonPath('data.building.status', 'active')
            ->assertJsonPath('data.cost_info.historical_count_n', 2)
            ->assertJsonPath('data.cost_info.cost.fog', '115.000000');

        // Очищаем fog для проверки отказа при нехватке ресурсов
        DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->update(['balance' => '0.000000']);

        $this->withToken($plainToken)->postJson('/api/v1/city/buildings', [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'mist_harvester',
            'position_x' => 5,
            'position_y' => 5,
            'now' => $t0->addMinutes(25)->toIso8601String(),
        ])->assertStatus(422);
    }
}
