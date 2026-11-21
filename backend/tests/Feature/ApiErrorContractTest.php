<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\WorldPhase\Models\WorldPhase;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiErrorContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_errors_follow_unified_error_and_request_id_contract(): void
    {
        // Невалидный initData -> 401 TELEGRAM_AUTH_INVALID + meta.request_id
        $this->postJson('/api/v1/auth/telegram', [
            'init_data' => 'auth_date=1700000000&hash=invalid_hash',
        ])
            ->assertStatus(401)
            ->assertJsonStructure([
                'error' => ['code', 'message'],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('error.code', 'TELEGRAM_AUTH_INVALID');

        // Несуществующий ресурс -> 404 RESOURCE_NOT_FOUND + meta.request_id
        $this->getJson('/api/v1/non-existent-endpoint-check')
            ->assertStatus(404)
            ->assertJsonStructure([
                'error' => ['code', 'message'],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }

    public function test_world_phase_schedule_gap_is_detected_by_service(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        // Фаза покрывает только первые 30 минут, затем наступает разрыв
        WorldPhase::create([
            'phase_name' => 'twilight',
            'multiplier_fog' => '1.000000',
            'starts_at' => $t0,
            'ends_at' => $t0->addMinutes(30),
        ]);

        $service = new WorldPhaseService();

        $this->expectException(DomainException::class);
        $service->assertContinuousSchedule($t0, $t0->addHour());
    }

    public function test_idempotency_key_conflict_returns_409_on_sync_fog(): void
    {
        $player = Player::create(['telegram_id' => 7702, 'first_name' => 'IdemHttpGuard']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '0.000000',
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
            'name' => 'Idem City',
            'economy_calculated_at' => $t0,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '100.000000',
                'idempotency_key' => 'idem-http-conflict-001',
                'now' => $t0->addHour()->toIso8601String(),
            ])
            ->assertStatus(201);

        $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '250.000000',
                'idempotency_key' => 'idem-http-conflict-001',
                'now' => $t0->addHour()->toIso8601String(),
            ])
            ->assertStatus(409)
            ->assertJsonStructure([
                'error' => ['code', 'message'],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }
}
