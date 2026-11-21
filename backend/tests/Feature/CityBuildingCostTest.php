<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\City\Models\Building;
use App\Modules\City\Services\CityService;

class CityBuildingCostTest extends TestCase
{
    use RefreshDatabase;

    public function test_building_cost_accounts_for_historical_n_including_demolished(): void
    {
        // Создаем запись в каталоге зданий для внешнего ключа
        $catalogId = DB::table('building_catalog')->insertGetId([
            'building_key' => 'inkwell',
            'category' => 'production',
            'display_name' => 'Inkwell',
            'footprint_width' => 2,
            'footprint_height' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Создаем игрока и город
        $player = Player::create([
            'telegram_id' => 123456789,
            'first_name' => 'Alchemist'
        ]);

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Dream City',
            'economy_calculated_at' => now(),
        ]);

        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        $cityService = new CityService();

        // 1. Проверяем стоимость для первого здания (N = 1)
        $cost1 = $cityService->calculateBuildingCost($city, 'inkwell');
        $this->assertEquals(1, $cost1['historical_count_n']);
        $this->assertEquals('100.000000', $cost1['cost']['fog']);

        // Создаем первое здание
        $b1 = Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalogId,
            'building_type' => 'inkwell',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => now(),
        ]);

        // Создаем второе здание
        $b2 = Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalogId,
            'building_type' => 'inkwell',
            'level' => 1,
            'position_x' => 2,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => now(),
        ]);

        // Проверяем стоимость для третьего здания до сноса (N = 3)
        $costBeforeDemolish = $cityService->calculateBuildingCost($city, 'inkwell');
        $this->assertEquals(3, $costBeforeDemolish['historical_count_n']);

        // Сносим первое здание (статус demolished)
        $b1->update([
            'status' => 'demolished',
            'demolished_at' => now(),
        ]);

        // Проверяем, что после сноса одного из зданий исторический фактор N остался равным 3
        $costAfterDemolish = $cityService->calculateBuildingCost($city, 'inkwell');
        $this->assertEquals(3, $costAfterDemolish['historical_count_n'], 'Снесенное здание должно учитываться в историческом факторе N, а N не должен уменьшаться');
    }
}
