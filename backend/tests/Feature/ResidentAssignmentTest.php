<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Residents\Models\Resident;
use App\Modules\Residents\Services\ResidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class ResidentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_assignment_and_city_isolation(): void
    {
        $catalogId = DB::table('building_catalog')->insertGetId([
            'building_key' => 'inkwell',
            'category' => 'production',
            'display_name' => 'Inkwell',
            'footprint_width' => 2,
            'footprint_height' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $player1 = Player::create(['telegram_id' => 1001, 'first_name' => 'DreamerOne']);
        $city1 = City::create([
            'player_id' => $player1->id,
            'name' => 'City One',
            'economy_calculated_at' => now(),
        ]);
        $island1 = Island::create([
            'city_id' => $city1->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);
        $building1 = Building::create([
            'city_id' => $city1->id,
            'island_id' => $island1->id,
            'building_catalog_id' => $catalogId,
            'building_type' => 'inkwell',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => now(),
        ]);

        $player2 = Player::create(['telegram_id' => 1002, 'first_name' => 'DreamerTwo']);
        $city2 = City::create([
            'player_id' => $player2->id,
            'name' => 'City Two',
            'economy_calculated_at' => now(),
        ]);

        $service = new ResidentService();

        $resident1 = $service->createResident($city1, 'dream', 'Соня', '10.00', 1);
        $assigned = $service->assignToBuilding($resident1, $building1);

        $this->assertEquals('assigned', $assigned->status);
        $this->assertEquals($building1->id, $assigned->assigned_building_id);

        // Житель из чужого города не может быть назначен на здание city1
        $resident2 = $service->createResident($city2, 'memory', 'Архивариус', '10.00', 0);

        $this->expectException(InvalidArgumentException::class);
        $service->assignToBuilding($resident2, $building1);
    }

    public function test_cannot_assign_resident_on_expedition(): void
    {
        $catalogId = DB::table('building_catalog')->insertGetId([
            'building_key' => 'inkwell',
            'category' => 'production',
            'display_name' => 'Inkwell',
            'footprint_width' => 2,
            'footprint_height' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $player = Player::create(['telegram_id' => 1003, 'first_name' => 'Explorer']);
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Expedition City',
            'economy_calculated_at' => now(),
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);
        $building = Building::create([
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

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'phobia',
            'name' => 'Тень',
            'trust_level' => 0,
            'energy' => '5.00',
            'status' => 'expedition',
            'assigned_building_id' => null,
        ]);

        $service = new ResidentService();

        $this->expectException(RuntimeException::class);
        $service->assignToBuilding($resident, $building);
    }
}
