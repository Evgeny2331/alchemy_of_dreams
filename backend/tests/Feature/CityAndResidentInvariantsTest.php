<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\City\Services\CityService;
use App\Modules\Residents\Models\Resident;
use App\Modules\Residents\Services\ResidentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CityAndResidentInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_assign_resident_to_building_from_another_city(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $playerA = Player::create(['telegram_id' => 9001, 'first_name' => 'LordA']);
        $cityA = City::create([
            'player_id' => $playerA->id,
            'name' => 'City A',
            'economy_calculated_at' => $t0,
        ]);

        $playerB = Player::create(['telegram_id' => 9002, 'first_name' => 'LordB']);
        $cityB = City::create([
            'player_id' => $playerB->id,
            'name' => 'City B',
            'economy_calculated_at' => $t0,
        ]);

        $islandB = Island::create([
            'city_id' => $cityB->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        $catalog = BuildingCatalog::create([
            'building_key' => 'fog_condenser',
            'category' => 'functional',
            'display_name' => 'Конденсатор Тумана',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        $buildingB = Building::create([
            'city_id' => $cityB->id,
            'island_id' => $islandB->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'fog_condenser',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => $t0,
            'demolished_at' => null,
        ]);

        $residentA = Resident::create([
            'city_id' => $cityA->id,
            'archetype' => 'dream',
            'name' => 'Искатель A',
            'trust_level' => 1,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $residentService = new ResidentService();

        $this->expectException(InvalidArgumentException::class);
        $residentService->assignToBuilding($residentA, $buildingB);
    }

    public function test_historical_building_count_includes_demolished_buildings(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $player = Player::create(['telegram_id' => 9003, 'first_name' => 'Architect']);
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Architect Spire',
            'economy_calculated_at' => $t0,
        ]);

        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        $catalog = BuildingCatalog::create([
            'building_key' => 'dream_loom',
            'category' => 'functional',
            'display_name' => 'Ткач Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        // 1 снесённое здание + 1 активное => в истории 2 здания, следующее будет N = 3
        Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'dream_loom',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'demolished',
            'built_at' => $t0,
            'demolished_at' => $t0->addHour(),
        ]);

        Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'dream_loom',
            'level' => 1,
            'position_x' => 4,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => $t0->addHours(2),
            'demolished_at' => null,
        ]);

        $cityService = new CityService();
        $costInfo = $cityService->calculateBuildingCost($city, 'dream_loom');

        $this->assertEquals(3, $costInfo['historical_count_n']);
        $this->assertEquals('133.000000', $costInfo['cost']['fog']);
    }
}
