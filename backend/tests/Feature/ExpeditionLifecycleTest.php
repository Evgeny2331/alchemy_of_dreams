<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Expedition\Services\ExpeditionService;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ExpeditionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_expedition_start_complete_and_prevent_double_claim(): void
    {
        $player = Player::create(['telegram_id' => 2001, 'first_name' => 'Voyager']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '50.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Dream Harbor',
            'economy_calculated_at' => CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC'),
        ]);

        $r1 = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'memory',
            'name' => 'Хранитель',
            'trust_level' => 1,
            'energy' => '5.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $r2 = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Искатель',
            'trust_level' => 0,
            'energy' => '4.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $service = new ExpeditionService();
        $start = CarbonImmutable::parse('2026-11-21 01:00:00', 'UTC');

        $expedition = $service->startExpedition($city, 'surface', [$r1->id, $r2->id], '1.0', $start);

        $this->assertEquals('active', $expedition->status);
        $this->assertCount(2, $expedition->participants);
        $this->assertEquals('4.00', $r1->fresh()->energy);
        $this->assertEquals('expedition', $r1->fresh()->status);
        $this->assertEquals('35.000000', (string) DB::table('player_resources')->where('player_id', $player->id)->where('resource_type', 'fog')->value('balance'));

        $this->expectException(RuntimeException::class);
        $service->claimReward($expedition, $start->addMinutes(2));
    }

    public function test_expedition_reward_claimed_only_once(): void
    {
        $player = Player::create(['telegram_id' => 2002, 'first_name' => 'Navigator']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '50.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Astral Port',
            'economy_calculated_at' => CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC'),
        ]);

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'memory',
            'name' => 'Следопыт',
            'trust_level' => 2,
            'energy' => '6.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $service = new ExpeditionService();
        $start = CarbonImmutable::parse('2026-11-21 01:00:00', 'UTC');

        $expedition = $service->startExpedition($city, 'surface', [$resident->id], '1.0', $start);
        $completed = $service->completeExpedition($expedition, $start->addMinutes(5));

        $this->assertEquals('completed', $completed->status);
        $this->assertNotNull($completed->reward_payload);

        $firstClaim = $service->claimReward($completed, $start->addMinutes(6));
        $this->assertEquals('claimed', $firstClaim['expedition']->status);
        $this->assertArrayHasKey('memories', $firstClaim['rewards']);

        $this->expectException(RuntimeException::class);
        $service->claimReward($completed, $start->addMinutes(7));
    }
}
