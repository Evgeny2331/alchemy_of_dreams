<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Economy\Services\OfflineEconomyService;
use App\Modules\Expedition\Services\ExpeditionService;
use App\Modules\Residents\Models\Resident;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class EconomyAndExpeditionInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_key_payload_mismatch_is_rejected(): void
    {
        $player = Player::create(['telegram_id' => 8001, 'first_name' => 'IdemGuard']);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Guard City',
            'economy_calculated_at' => $t0,
        ]);

        $service = new OfflineEconomyService(new WorldPhaseService());

        $first = $service->syncFogProduction($player, $city, '100.000000', 'shared-key-1', $t0->addHour());
        $this->assertEquals('100.000000', $first['fog_earned']);

        $this->expectException(InvalidArgumentException::class);
        $service->syncFogProduction($player, $city, '200.000000', 'shared-key-1', $t0->addHour());
    }

    public function test_expedition_double_claim_does_not_duplicate_rewards(): void
    {
        $player = Player::create(['telegram_id' => 8002, 'first_name' => 'ClaimGuard']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '50.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Expedition Hold',
            'economy_calculated_at' => $t0,
        ]);

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Следопыт',
            'trust_level' => 2,
            'energy' => '10.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $expService = new ExpeditionService();
        $started = $expService->startExpedition($city, 'surface', [$resident->id], '1.0', $t0);
        $claimed = $expService->claimReward($started, $t0->addMinutes(10));

        $this->assertEquals('claimed', $claimed['expedition']->status);

        $ledgersAfterFirstClaim = DB::table('resource_ledgers')
            ->where('player_id', $player->id)
            ->count();

        $this->expectException(RuntimeException::class);
        $expService->claimReward($claimed['expedition'], $t0->addMinutes(15));

        $ledgersAfterSecondClaim = DB::table('resource_ledgers')
            ->where('player_id', $player->id)
            ->count();
        $this->assertEquals($ledgersAfterFirstClaim, $ledgersAfterSecondClaim);
    }

    public function test_concurrent_build_collision_and_idempotent_replay_prevent_double_spend(): void
    {
        $player = Player::create(['telegram_id' => 8003, 'first_name' => 'RaceGuard']);
        foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $res) {
            DB::table('player_resources')->insert([
                'player_id' => $player->id,
                'resource_type' => $res,
                'balance' => $res === 'fog' ? '150.000000' : '0.000000',
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
            'name' => 'Race City',
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

        $payload = [
            'city_id' => $city->id,
            'island_id' => $island->id,
            'building_key' => 'inkwell',
            'position' => ['x' => 2, 'y' => 2],
            'now' => $t0->toIso8601String(),
        ];

        $first = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'race-build-key-1'])
            ->postJson('/api/v1/buildings', $payload)
            ->assertStatus(201);

        $replay = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'race-build-key-1'])
            ->postJson('/api/v1/buildings', $payload)
            ->assertStatus(201);

        $this->assertSame($first->json('data.building.id'), $replay->json('data.building.id'));

        $second = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'race-build-key-2'])
            ->postJson('/api/v1/buildings', $payload);

        $this->assertContains($second->getStatusCode(), [400, 409, 422]);

        $activeBuildingsCount = DB::table('buildings')
            ->where('city_id', $city->id)
            ->where('status', 'active')
            ->count();
        $this->assertSame(1, $activeBuildingsCount);

        $fogBalance = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertGreaterThanOrEqual(0, (float) $fogBalance);
    }
}
