<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResidentRequestsTest extends TestCase
{
    use RefreshDatabase;

    public function test_resident_request_cost_cap_completion_and_decline_flow(): void
    {
        $player = Player::create(['telegram_id' => 6901, 'first_name' => 'RequestTester']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '100.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'memories',
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
            'name' => 'Request City',
            'economy_calculated_at' => $t0,
        ]);
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Сновидец',
            'trust_level' => 1,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        // 15% от 100.000000 fog = 15.000000; 10% = 10.000000
        $gen = $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->toIso8601String(),
            ])->assertStatus(201);

        $reqId = (int) $gen->json('data.id');
        $costAmount = (float) $gen->json('data.cost_payload.amount');
        $this->assertLessThanOrEqual(15.0, $costAmount);

        // Выполнение запроса списывает стоимость, начисляет memories и повышает trust_level
        $this->withToken($token)
            ->postJson("/api/v1/resident-requests/{$reqId}/complete", [
                'now' => $t0->addMinutes(5)->toIso8601String(),
            ])->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $resident->refresh();
        $this->assertSame(2, (int) $resident->trust_level);

        $memories = (string) DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'memories')
            ->value('balance');
        $this->assertSame('5.000000', $memories);

        // Повторная генерация раньше кулдауна (6 часов) отклоняется
        $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(2)->toIso8601String(),
            ])->assertStatus(409);

        // Генерация после кулдауна и отклонение (decline) проходят без блокировки прогресса
        $gen2 = $this->withToken($token)
            ->postJson("/api/v1/residents/{$resident->id}/requests", [
                'now' => $t0->addHours(7)->toIso8601String(),
            ])->assertStatus(201);

        $req2Id = (int) $gen2->json('data.id');
        $this->withToken($token)
            ->postJson("/api/v1/resident-requests/{$req2Id}/decline")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'declined');
    }
}
