<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\OfflineEconomyService;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfflineEconomySyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_offline_fog_sync_respects_phases_and_idempotency_key(): void
    {
        $player = Player::create(['telegram_id' => 4001, 'first_name' => 'Economist']);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Fog Spire',
            'economy_calculated_at' => $t0,
        ]);

        $phaseService = new WorldPhaseService();
        $phaseService->createPhase('surge', '2.000000', $t0->addHour(), $t0->addHours(2));

        $syncService = new OfflineEconomyService($phaseService);
        $now = $t0->addHours(2);

        // Первый вызов: (1ч * 100 * 1.0 + 1ч * 100 * 2.0) = 300.000000, но лимит базового склада = 200.000000
        $first = $syncService->syncFogProduction($player, $city, '100.000000', 'idem-key-1', $now);

        $this->assertFalse($first['idempotent_replay']);
        $this->assertEquals('200.000000', $first['fog_earned']);

        $balanceAfterFirst = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertEquals('200.000000', $balanceAfterFirst);

        // Повторный вызов с тем же idempotency_key не должен начислять fog повторно
        $replay = $syncService->syncFogProduction($player, $city, '100.000000', 'idem-key-1', $now);

        $this->assertTrue($replay['idempotent_replay']);
        $this->assertEquals('200.000000', $replay['fog_earned']);

        $balanceAfterReplay = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertEquals('200.000000', $balanceAfterReplay);

        $this->assertEquals(1, DB::table('resource_ledgers')->where('player_id', $player->id)->count());
        $this->assertEquals(1, DB::table('transaction_outbox')->count());
        $this->assertEquals(1, DB::table('idempotency_records')->where('player_id', $player->id)->count());
    }
}


