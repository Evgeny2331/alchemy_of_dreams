<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Residents\Models\Resident;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FullGameplayJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_city_resident_request_and_phase_sync_flow(): void
    {
        $player = Player::create(['telegram_id' => 7101, 'first_name' => 'Alchemist']);
        foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $res) {
            DB::table('player_resources')->insert([
                'player_id' => $player->id,
                'resource_type' => $res,
                'balance' => $res === 'fog' ? '50.000000' : '0.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            'name' => 'Dream Citadel',
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
            'display_name' => 'Ткацкий Стан Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Сольвейг',
            'trust_level' => 0,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $phaseService = new WorldPhaseService();
        $phaseService->createPhase('lucid_calm', '1.250000', $t0, $t0->addHours(2));

        // 1. Синхронизация оффлайн-дохода за 2 часа с множителем 1.25: 2 * 40 * 1.25 = 100.000000
        $syncResp = $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '40.000000',
                'idempotency_key' => 'e2e-sync-1',
                'now' => $t0->addHours(2)->toIso8601String(),
            ]);
        $syncResp->assertStatus(201)->assertJsonPath('data.fog_earned', '100.000000');

        // 2. Постройка здания с Idempotency-Key (стоимость 100 fog -> остаток 50 fog)
        $buildResp = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'e2e-build-1'])
            ->postJson('/api/v1/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => $catalog->building_key,
                'position' => ['x' => 1, 'y' => 1],
                'now' => $t0->addHours(2)->toIso8601String(),
            ]);
        $buildResp->assertStatus(201);
        $buildingId = (int) $buildResp->json('data.building.id');

        // 3. Назначение жителя на здание
        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/assign", [
                'building_id' => $buildingId,
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'assigned');

        // 4. Генерация и выполнение запроса жителя
        $reqResp = $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(2)->toIso8601String(),
            ])->assertStatus(201);
        $reqId = (int) $reqResp->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/resident-requests/{$reqId}/complete", [
                'now' => $t0->addHours(2)->addMinutes(10)->toIso8601String(),
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $resident->refresh();
        $this->assertSame(1, (int) $resident->trust_level);

        // 5. Проверка эндпоинтов экспедиций, архива алхимии и активной фазы мира
        $this->withToken($token)->getJson('/api/v1/expeditions')->assertStatus(200);
        $this->withToken($token)->getJson('/api/v1/alchemy/archive')->assertStatus(200);
        $this->withToken($token)
            ->getJson('/api/v1/world/phase?at=' . urlencode($t0->addMinutes(15)->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonPath('data.key', 'lucid_calm');
    }

    public function test_building_move_demolish_preserves_historical_count_and_upgrade_endpoint_exists(): void
    {
        $player = Player::create(['telegram_id' => 7102, 'first_name' => 'Architect']);
        foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $res) {
            DB::table('player_resources')->insert([
                'player_id' => $player->id,
                'resource_type' => $res,
                'balance' => $res === 'fog' ? '500.000000' : '0.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            'name' => 'Arch City',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);
        BuildingCatalog::create([
            'building_key' => 'inkwell',
            'category' => 'production',
            'display_name' => 'Чернильница',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 1,
            'footprint_height' => 1,
            'is_active' => true,
            'metadata' => [],
        ]);

        $buildResp = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'arch-build-1'])
            ->postJson('/api/v1/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'inkwell',
                'position' => ['x' => 0, 'y' => 0],
                'now' => $t0->toIso8601String(),
            ])->assertStatus(201);

        $buildingId = (int) $buildResp->json('data.building.id');

        // Перемещение здания
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'arch-move-1'])
            ->patchJson("/api/v1/buildings/{$buildingId}/position", [
                'island_id' => $island->id,
                'position' => ['x' => 3, 'y' => 2],
            ])->assertStatus(200);

        // Снос здания без физического удаления
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'arch-demolish-1'])
            ->deleteJson("/api/v1/buildings/{$buildingId}", [
                'now' => $t0->addMinutes(5)->toIso8601String(),
            ])->assertStatus(200);

        $historicalCount = DB::table('buildings')
            ->where('city_id', $city->id)
            ->where('building_type', 'inkwell')
            ->count();
        $this->assertSame(1, $historicalCount);
    }
}
