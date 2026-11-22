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

class FullGameplayApiFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_phase_sync_expedition_and_alchemy_archive_flow(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        DB::table('world_phases')->insert([
            'phase_name' => 'lucid_calm',
            'multiplier_fog' => '1.200000',
            'starts_at' => $t0->subHour(),
            'ends_at' => $t0->addHours(6),
            'created_at' => $t0,
        ]);

        $player = Player::create(['telegram_id' => 7701, 'first_name' => 'Voyager']);
        foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $res) {
            DB::table('player_resources')->insert([
                'player_id' => $player->id,
                'resource_type' => $res,
                'balance' => $res === 'fog' ? '200.000000' : ($res === 'metaphors' ? '5.000000' : '0.000000'),
                'created_at' => $t0,
                'updated_at' => $t0,
            ]);
        }

        $token = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => CarbonImmutable::now('UTC')->addDay(),
            'last_used_at' => $t0,
        ]);

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Astral Harbor',
            'economy_calculated_at' => $t0,
        ]);
        Island::create(['city_id' => $city->id, 'island_index' => 0, 'is_unlocked' => true]);

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'memory',
            'name' => 'Архивариус',
            'trust_level' => 1,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        // 1. Текущая фаза мира
        $this->withToken($token)
            ->getJson('/api/v1/world/phase?at=' . urlencode($t0->toIso8601String()))
            ->assertStatus(200)
            ->assertJsonPath('data.key', 'lucid_calm')
            ->assertJsonPath('data.multipliers.fog', '1.200000')
            ->assertJsonStructure(['data', 'meta' => ['request_id']]);

        // 2. Синхронизация состояния игры
        $this->withToken($token)
            ->getJson('/api/v1/game/sync')
            ->assertStatus(200)
            ->assertJsonPath('data.city.id', $city->id);

        // 3. Запуск, завершение и клейм экспедиции с Idempotency-Key
        $startResp = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'idem-exp-start-7701')
            ->postJson('/api/v1/expeditions', [
                'city_id' => $city->id,
                'expedition_type' => 'surface',
                'resident_ids' => [$resident->id],
                'now' => $t0->toIso8601String(),
            ]);

        $startResp->assertStatus(201);
        $expeditionId = (int) $startResp->json('data.id');

        $this->withToken($token)
            ->postJson("/api/v1/expeditions/{$expeditionId}/complete", [
                'now' => $t0->addMinutes(10)->toIso8601String(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'completed');

        $claimKey = 'idem-exp-claim-7701';
        $this->withToken($token)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/v1/expeditions/{$expeditionId}/claim", [
                'now' => $t0->addMinutes(10)->toIso8601String(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.expedition.status', 'claimed');

        // Повторный клейм с тем же ключом не начисляет награду повторно
        $this->withToken($token)
            ->withHeader('Idempotency-Key', $claimKey)
            ->postJson("/api/v1/expeditions/{$expeditionId}/claim", [
                'now' => $t0->addMinutes(10)->toIso8601String(),
            ])
            ->assertStatus(200);

        // 4. Алхимический эксперимент и проверка Архива рецептов
        $recipe = Recipe::create([
            'recipe_key' => 'lantern_of_echoes',
            'category' => 'functional',
            'result_type' => 'artifact',
            'result_key' => 'echo_lantern',
            'metadata' => [],
            'is_active' => true,
        ]);
        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'resource',
            'component_key' => 'metaphors',
            'quantity' => '1.000000',
        ]);

        $this->withToken($token)
            ->withHeader('Idempotency-Key', 'idem-alchemy-7701')
            ->postJson('/api/v1/alchemy/experiment', [
                'components' => [
                    [
                        'component_type' => 'resource',
                        'component_key' => 'metaphors',
                        'quantity' => '1.000000',
                    ],
                ],
                'now' => $t0->addMinutes(15)->toIso8601String(),
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.newly_unlocked', true);

        $this->withToken($token)
            ->getJson('/api/v1/alchemy/archive')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data.recipes');
    }
}


