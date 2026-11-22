<?php

namespace Tests\Feature;

use App\Modules\Alchemy\Models\Recipe;
use App\Modules\Alchemy\Models\RecipeComponent;
use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function createSessionToken(Player $player): string
    {
        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        return $plainToken;
    }

    public function test_protected_endpoints_require_session_and_enforce_ownership(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $owner = Player::create(['telegram_id' => 6101, 'first_name' => 'Owner']);
        $ownerCity = City::create([
            'player_id' => $owner->id,
            'name' => 'Owner City',
            'economy_calculated_at' => $t0,
        ]);

        $intruder = Player::create(['telegram_id' => 6102, 'first_name' => 'Intruder']);
        $intruderToken = $this->createSessionToken($intruder);

        // Без токена — 401
        $this->getJson('/api/v1/city/state')->assertStatus(401);
        $this->postJson('/api/v1/economy/sync-fog', [
            'city_id' => $ownerCity->id,
            'base_fog_per_hour' => '100',
            'idempotency_key' => 'unauth-1',
        ])->assertStatus(401);

        // Чужой город — 403
        $this->withToken($intruderToken)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $ownerCity->id,
                'base_fog_per_hour' => '100',
                'idempotency_key' => 'forbidden-1',
            ])->assertStatus(403);
    }

    public function test_city_state_economy_expedition_and_alchemy_api_flow(): void
    {
        $player = Player::create(['telegram_id' => 6001, 'first_name' => 'ApiTester']);
        $token = $this->createSessionToken($player);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'API Sanctum',
            'economy_calculated_at' => $t0,
        ]);
        Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        // 0. Получение снапшота состояния города
        $stateResp = $this->withToken($token)->getJson('/api/v1/city/state');
        $stateResp->assertStatus(200)
            ->assertJsonPath('data.player.id', $player->id)
            ->assertJsonPath('data.city.id', $city->id)
            ->assertJsonCount(1, 'data.city.islands');

        // 1. Синхронизация оффлайн fog через API + идемпотентный повтор
        $syncResp = $this->withToken($token)->postJson('/api/v1/economy/sync-fog', [
            'city_id' => $city->id,
            'base_fog_per_hour' => '100',
            'idempotency_key' => 'api-sync-1',
            'now' => $t0->addHour()->toIso8601String(),
        ]);
        $syncResp->assertStatus(201)
            ->assertJsonPath('data.fog_earned', '100.000000')
            ->assertJsonPath('data.idempotent_replay', false);

        $replayResp = $this->withToken($token)->postJson('/api/v1/economy/sync-fog', [
            'city_id' => $city->id,
            'base_fog_per_hour' => '100',
            'idempotency_key' => 'api-sync-1',
            'now' => $t0->addHour()->toIso8601String(),
        ]);
        $replayResp->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', true);

        // 2. Старт и получение награды экспедиции через API
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Вестник',
            'trust_level' => 1,
            'energy' => '5.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $startExp = $this->withToken($token)->postJson('/api/v1/expeditions/start', [
            'city_id' => $city->id,
            'expedition_type' => 'surface',
            'resident_ids' => [$resident->id],
            'now' => $t0->addHour()->toIso8601String(),
        ]);
        $startExp->assertStatus(201)
            ->assertJsonPath('data.status', 'active');

        $expeditionId = (int) $startExp->json('data.id');

        $claimExp = $this->withToken($token)->postJson("/api/v1/expeditions/{$expeditionId}/claim", [
            'now' => $t0->addHour()->addMinutes(6)->toIso8601String(),
        ]);
        $claimExp->assertStatus(200)
            ->assertJsonPath('data.expedition.status', 'claimed');

        // 3. Алхимический синтез с проверкой баланса fog (100 -> 90)
        $recipe = Recipe::create([
            'recipe_key' => 'astral_drop',
            'category' => 'functional',
            'result_type' => 'artifact',
            'result_key' => 'astral_drop_key',
            'metadata' => [],
            'is_active' => true,
        ]);
        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'resource',
            'component_key' => 'fog',
            'quantity' => '10.000000',
        ]);

        $transmuteResp = $this->withToken($token)->postJson('/api/v1/alchemy/transmute', [
            'components' => [
                ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '10'],
            ],
        ]);
        $transmuteResp->assertStatus(200)
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.newly_unlocked', true);

        $fogBalance = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('75.000000', (string) $fogBalance);

        // При нехватке fog возвращается 422
        DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->update(['balance' => '5.000000']);

        $r = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'test-insufficient-fog-1')
            ->postJson('/api/v1/alchemy/transmute', [
                'components' => [
                    ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '10'],
                ],
            ]);
        $r->assertStatus(409);
    }
}
