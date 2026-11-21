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

class SessionLifecycleAndConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_logout_revocation_and_expired_token_rejection(): void
    {
        $player = Player::create(['telegram_id' => 6801, 'first_name' => 'SessionTester']);
        $activeToken = Str::random(64);

        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $activeToken),
            'expires_at' => now()->addHour(),
            'last_used_at' => now(),
        ]);

        $this->withToken($activeToken)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('data.revoked', true);

        // Отозванный токен больше не допускается
        $this->withToken($activeToken)
            ->getJson('/api/v1/game/sync')
            ->assertStatus(401);

        // Просроченный токен отклоняется
        $expiredToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $expiredToken),
            'expires_at' => now()->subMinute(),
            'last_used_at' => now()->subHours(2),
        ]);

        $this->withToken($expiredToken)
            ->getJson('/api/v1/game/sync')
            ->assertStatus(401);
    }

    public function test_repeated_build_with_insufficient_balance_and_idempotency_never_overdraws(): void
    {
        $player = Player::create(['telegram_id' => 6802, 'first_name' => 'BalanceGuard']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '150.000000',
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
            'name' => 'Guard City',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);
        BuildingCatalog::create([
            'building_key' => 'inkwell',
            'category' => 'functional',
            'display_name' => 'Чернильница',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        // Первое здание стоит 100 fog -> остается 50 fog (не хватает на второе за 115 fog)
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'req-build-1'])
            ->postJson('/api/v1/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'inkwell',
                'position' => ['x' => 0, 'y' => 0],
                'now' => $t0->toIso8601String(),
            ])->assertStatus(201);

        // Повтор того же ключа возвращает 201 replay без списания
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'req-build-1'])
            ->postJson('/api/v1/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'inkwell',
                'position' => ['x' => 0, 'y' => 0],
                'now' => $t0->toIso8601String(),
            ])->assertStatus(201)
            ->assertJsonPath('data.idempotent_replay', true);

        // Новый запрос на второе здание на той же позиции или при нехватке баланса отклоняется (422)
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'req-build-2'])
            ->postJson('/api/v1/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'inkwell',
                'position' => ['x' => 2, 'y' => 2],
                'now' => $t0->toIso8601String(),
            ])->assertStatus(422);

        $balance = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('50.000000', (string) $balance);
    }
}
