<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\OfflineEconomyService;
use App\Modules\Expedition\Services\ExpeditionService;
use App\Modules\Residents\Models\Resident;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
