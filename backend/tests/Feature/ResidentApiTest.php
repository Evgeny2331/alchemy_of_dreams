<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_assign_and_unassign_resident_via_api(): void
    {
        $player = Player::create(['telegram_id' => 6401, 'first_name' => 'Guildmaster']);
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
            'name' => 'Sanctuary',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);
        $catalog = BuildingCatalog::create([
            'building_key' => 'dream_forge',
            'category' => 'functional',
            'display_name' => 'Кузня Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);
        $building = Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'dream_forge',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => $t0,
            'demolished_at' => null,
        ]);
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Лира',
            'trust_level' => 2,
            'energy' => '8.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        // Список жителей города
        $listResp = $this->withToken($plainToken)
            ->getJson("/api/v1/city/{$city->id}/residents");
        $listResp->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $resident->id);

        // Назначение на здание
        $assignResp = $this->withToken($plainToken)
            ->postJson("/api/v1/residents/{$resident->id}/assign", [
                'building_id' => $building->id,
            ]);
        $assignResp->assertStatus(200)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assigned_building_id', $building->id);

        // Снятие со здания
        $unassignResp = $this->withToken($plainToken)
            ->postJson("/api/v1/residents/{$resident->id}/unassign");
        $unassignResp->assertStatus(200)
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.assigned_building_id', null);

        // Нельзя снять жителя, который находится в экспедиции -> 422
        $resident->update(['status' => 'expedition']);
        $this->withToken($plainToken)
            ->postJson("/api/v1/residents/{$resident->id}/unassign")
            ->assertStatus(409);
    }
}
