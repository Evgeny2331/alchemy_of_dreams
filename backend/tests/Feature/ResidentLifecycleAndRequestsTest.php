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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentLifecycleAndRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_assignment_and_request_cooldown_and_completion(): void
    {
        $player = Player::create(['telegram_id' => 7501, 'first_name' => 'DreamKeeper']);
        DB::table('player_resources')->insert([
            [
                'player_id' => $player->id,
                'resource_type' => 'fog',
                'balance' => '100.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'player_id' => $player->id,
                'resource_type' => 'memories',
                'balance' => '0.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
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
            'name' => 'Citadel of Dreams',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create(['city_id' => $city->id, 'island_index' => 0, 'is_unlocked' => true]);
        $catalog = BuildingCatalog::create([
            'building_key' => 'dream_loom',
            'category' => 'functional',
            'display_name' => 'Ткацкий Стан Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
        ]);
        $building = Building::create([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalog->id,
            'building_type' => 'dream_loom',
            'level' => 1,
            'position_x' => 0,
            'position_y' => 0,
            'status' => 'active',
            'built_at' => $t0,
        ]);

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Морфей',
            'trust_level' => 0,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        // Назначение и снятие назначения
        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/assign", ['building_id' => $building->id])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.assigned_building_id', $building->id);

        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/unassign")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'available');

        // Генерация запроса с лимитом стоимости <= 15% (от 100 fog => макс 15.000000)
        $genResp = $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHour()->toIso8601String(),
            ]);

        $genResp->assertStatus(201)
            ->assertJsonPath('data.status', 'available');

        $costAmount = (float) $genResp->json('data.cost_payload.amount');
        $this->assertLessThanOrEqual(15.0, $costAmount);
        $requestId = (int) $genResp->json('data.id');

        // Повторная генерация при активном запросе отклоняется (422)
        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(2)->toIso8601String(),
            ])
            ->assertStatus(422);

        // Выполнение запроса повышает trust_level и начисляет награду
        $this->withToken($token)
            ->postJson("/api/v1/resident-requests/{$requestId}/complete", [
                'now' => $t0->addHours(2)->toIso8601String(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, (int) $resident->fresh()->trust_level);

        // Генерация до истечения 6-часового кулдауна отклоняется, после 6 часов — разрешена
        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(5)->toIso8601String(),
            ])
            ->assertStatus(422);

        $secondGen = $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(7)->toIso8601String(),
            ]);

        $secondGen->assertStatus(201);
        $secondReqId = (int) $secondGen->json('data.id');

        // Отклонение запроса переводит статус в declined
        $this->withToken($token)
            ->postJson("/api/v1/resident-requests/{$secondReqId}/decline")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'declined');
    }
}
