<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineEconomyPhaseSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_fog_splits_across_world_phases_and_is_idempotent(): void
    {
        $player = Player::create(['telegram_id' => 7001, 'first_name' => 'OfflineSyncTester']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '10.000000',
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
            'name' => 'Phase City',
            'economy_calculated_at' => $t0,
        ]);

        $phaseService = new WorldPhaseService();
        // 1-й час: множитель 1.5 -> при базовой 20/ч = 30.000000
        $phaseService->createPhase('lucid_surge', '1.500000', $t0, $t0->addHour());
        // 2-й час: множитель 0.5 -> при базовой 20/ч = 10.000000
        $phaseService->createPhase('deep_hush', '0.500000', $t0->addHour(), $t0->addHours(2));

        $target = $t0->addHours(2);

        $resp = $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '20.000000',
                'idempotency_key' => 'sync-phase-001',
                'now' => $target->toIso8601String(),
            ]);

        // Итого начислено: 30 + 10 = 40.000000, новый баланс = 50.000000
        $resp->assertStatus(201)
            ->assertJsonPath('data.fog_earned', '40.000000')
            ->assertJsonPath('data.idempotent_replay', false);

        $balance = (string) DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('50.000000', $balance);

        $city->refresh();
        $this->assertSame($target->timestamp, CarbonImmutable::instance($city->economy_calculated_at)->utc()->timestamp);

        // Повторный вызов с тем же ключом идемпотентно возвращает replay без повторного начисления
        $replay = $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '20.000000',
                'idempotency_key' => 'sync-phase-001',
                'now' => $target->toIso8601String(),
            ]);

        $replay->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', true)
            ->assertJsonPath('data.fog_earned', '40.000000');

        $balanceAfterReplay = (string) DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('50.000000', $balanceAfterReplay);
    }
}
