<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineEconomyStorageCapTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_fog_respects_phase_multipliers_and_storage_cap_without_carryover(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        // 2 часа фазы lucid_calm (1.2) + 2 часа фазы collective_nightmare (0.7)
        // При базовой ставке 100/час: (100 * 2 * 1.2) + (100 * 2 * 0.7) = 240 + 140 = 380
        DB::table('world_phases')->insert([
            [
                'phase_name' => 'lucid_calm',
                'multiplier_fog' => '1.200000',
                'starts_at' => $t0,
                'ends_at' => $t0->addHours(2),
                'created_at' => $t0,
            ],
            [
                'phase_name' => 'collective_nightmare',
                'multiplier_fog' => '0.700000',
                'starts_at' => $t0->addHours(2),
                'ends_at' => $t0->addHours(4),
                'created_at' => $t0,
            ],
        ]);

        $player = Player::create(['telegram_id' => 8801, 'first_name' => 'Dreamer']);
        foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $res) {
            DB::table('player_resources')->insert([
                'player_id' => $player->id,
                'resource_type' => $res,
                'balance' => $res === 'fog' ? '50.000000' : '0.000000',
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
            'name' => 'Cap City',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create(['city_id' => $city->id, 'island_index' => 0, 'is_unlocked' => true]);

        // Базовый лимит 200 + активное здание с storage_capacity 100 => StorageCap = 300
        // При балансе 50 свободно 250 места из 380 расчётных
        $catalogId = DB::table('building_catalog')->insertGetId([
            'building_key' => 'inkwell',
            'category' => 'production',
            'display_name' => 'Чернильница',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 1,
            'footprint_height' => 1,
            'is_active' => true,
            'metadata' => '{}',
            'created_at' => $t0,
            'updated_at' => $t0,
        ]);

        DB::table('building_level_configs')->insert([
            'building_catalog_id' => $catalogId,
            'level' => 1,
            'fog_per_hour' => '10.000000',
            'storage_capacity' => '100.000000',
            'build_time_seconds' => 0,
            'config' => '{}',
        ]);

        DB::table('buildings')->insert([
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_catalog_id' => $catalogId,
            'building_type' => 'inkwell',
            'level' => 1,
            'position_x' => 1,
            'position_y' => 1,
            'status' => 'active',
            'built_at' => $t0,
            'demolished_at' => null,
            'created_at' => $t0,
            'updated_at' => $t0,
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '100',
                'idempotency_key' => 'idem-cap-sync-1',
                'now' => $t0->addHours(4)->toIso8601String(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.fog_earned', '250.000000')
            ->assertJsonPath('data.storage_cap', '300.000000');

        $this->assertDatabaseHas('player_resources', [
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '300.000000',
        ]);

        // Следующий интервал при заполненном капе (300/300): доход 0, переполнения нет
        $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '100',
                'idempotency_key' => 'idem-cap-sync-2',
                'now' => $t0->addHours(5)->toIso8601String(),
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.fog_earned', '0.000000');
    }
}
