<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutboxCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_process_command_publishes_pending_events_idempotently(): void
    {
        $player = Player::create(['telegram_id' => 7301, 'first_name' => 'OutboxWorker']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '10.000000',
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
            'name' => 'Outbox Citadel',
            'economy_calculated_at' => $t0,
        ]);

        // Создаём доменное событие в transaction_outbox через API sync-fog
        $this->withToken($token)
            ->postJson('/api/v1/economy/sync-fog', [
                'city_id' => $city->id,
                'base_fog_per_hour' => '20.000000',
                'idempotency_key' => 'outbox-cmd-1',
                'now' => $t0->addHour()->toIso8601String(),
            ])->assertStatus(201);

        $this->assertSame(1, DB::table('transaction_outbox')->whereNull('published_at')->count());

        // Первый запуск команды публикует событие
        $this->artisan('outbox:process --limit=10')
            ->expectsOutputToContain('processed=1, failed=0, skipped=0')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('transaction_outbox')->whereNull('published_at')->count());
        $this->assertSame(1, (int) DB::table('transaction_outbox')->value('attempts'));

        // Повторный запуск не находит неопубликованных событий
        $this->artisan('outbox:process --limit=10')
            ->expectsOutputToContain('processed=0, failed=0, skipped=0')
            ->assertExitCode(0);
    }
}
