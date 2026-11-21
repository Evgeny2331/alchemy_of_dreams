<?php

namespace Tests\Feature;

use App\Modules\Alchemy\Models\Recipe;
use App\Modules\Alchemy\Models\RecipeComponent;
use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExpeditionAndAlchemyEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_expedition_double_claim_rejected_and_resident_returns_to_available(): void
    {
        $player = Player::create(['telegram_id' => 7201, 'first_name' => 'Explorer']);
        DB::table('player_resources')->insert([
            [
                'player_id' => $player->id,
                'resource_type' => 'memories',
                'balance' => '0.000000',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'player_id' => $player->id,
                'resource_type' => 'fog',
                'balance' => '50.000000',
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
            'name' => 'Expedition Haven',
            'economy_calculated_at' => $t0,
        ]);
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Кассиан',
            'trust_level' => 1,
            'energy' => '5.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $startResp = $this->withToken($token)
            ->postJson('/api/v1/expeditions/start', [
                'city_id' => $city->id,
                'expedition_type' => 'surface',
                'resident_ids' => [$resident->id],
                'now' => $t0->toIso8601String(),
            ]);
        $startResp->assertStatus(201);
        $expId = (int) $startResp->json('data.id');

        $resident->refresh();
        $this->assertSame('expedition', $resident->status);

        $claimResp = $this->withToken($token)
            ->postJson("/api/v1/expeditions/{$expId}/claim", [
                'now' => $t0->addSeconds(305)->toIso8601String(),
            ]);
        $claimResp->assertStatus(200);

        $resident->refresh();
        $this->assertSame('available', $resident->status);

        $this->withToken($token)
            ->postJson("/api/v1/expeditions/{$expId}/claim", [
                'now' => $t0->addSeconds(310)->toIso8601String(),
            ])->assertStatus(422);
    }

    public function test_alchemy_transmute_deducts_components_and_records_unmatched_event(): void
    {
        $player = Player::create(['telegram_id' => 7202, 'first_name' => 'Synthesizer']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '50.000000',
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

        $recipe = Recipe::create([
            'recipe_key' => 'lucid_metaphor',
            'category' => 'functional',
            'result_type' => 'artifact',
            'result_key' => 'crystal_of_metaphor',
            'metadata' => ['tier' => 1],
            'is_active' => true,
        ]);
        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'resource',
            'component_key' => 'fog',
            'quantity' => '20.000000',
        ]);

        // Неудачный эксперимент расходует базовый fog (5.000000) и пишет событие в outbox
        $this->withToken($token)
            ->postJson('/api/v1/alchemy/transmute', [
                'components' => [
                    ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '5.000000'],
                ],
            ])->assertStatus(200)
            ->assertJsonPath('data.matched', false);

        $this->assertSame(
            '45.000000',
            (string) DB::table('player_resources')->where('player_id', $player->id)->where('resource_type', 'fog')->value('balance')
        );
        $this->assertSame(
            1,
            DB::table('transaction_outbox')->where('event_type', 'alchemy.experiment.unmatched')->count()
        );

        // Совпадающий рецепт списывает 20 fog (остаётся 25) и добавляет рецепт в архив игрока
        $this->withToken($token)
            ->postJson('/api/v1/alchemy/transmute', [
                'components' => [
                    ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '20.000000'],
                ],
            ])->assertStatus(200)
            ->assertJsonPath('data.matched', true)
            ->assertJsonPath('data.newly_unlocked', true);

        $this->assertSame(
            '25.000000',
            (string) DB::table('player_resources')->where('player_id', $player->id)->where('resource_type', 'fog')->value('balance')
        );
    }
}
