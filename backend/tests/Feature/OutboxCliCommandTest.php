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

class OutboxCliCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_process_artisan_command_publishes_pending_events(): void
    {
        $player = Player::create(['telegram_id' => 7001, 'first_name' => 'CliWorker']);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'CLI Bastion',
            'economy_calculated_at' => $t0,
        ]);

        $syncService = new OfflineEconomyService(new WorldPhaseService());
        $syncService->syncFogProduction($player, $city, '75.000000', 'cli-outbox-1', $t0->addHour());

        $this->assertEquals(1, DB::table('transaction_outbox')->whereNull('published_at')->count());

        $this->artisan('outbox:process', ['--limit' => 10, '--max-attempts' => 3])
            ->expectsOutputToContain('Outbox batch completed: processed=1, failed=0, skipped=0')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('transaction_outbox')->whereNull('published_at')->count());
        $this->assertEquals(1, DB::table('transaction_outbox')->whereNotNull('published_at')->count());
    }
}
