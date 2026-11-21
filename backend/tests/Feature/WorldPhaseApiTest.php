<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class WorldPhaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_world_phase_endpoint_overlap_rejection_and_schedule_gap_check(): void
    {
        $player = Player::create(['telegram_id' => 6701, 'first_name' => 'PhaseTester']);
        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $service = new WorldPhaseService();

        $service->createPhase('lucid_calm', '1.200000', $t0, $t0->addHours(2));
        $service->createPhase('twilight', '1.000000', $t0->addHours(2), $t0->addHours(4));

        // Пересечение интервалов должно отклоняться
        $this->expectException(InvalidArgumentException::class);
        $service->createPhase('collective_nightmare', '0.700000', $t0->addHour(), $t0->addHours(3));
    }

    public function test_get_world_phase_returns_active_phase_and_detects_gaps(): void
    {
        $player = Player::create(['telegram_id' => 6702, 'first_name' => 'PhaseReader']);
        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $service = new WorldPhaseService();
        $service->createPhase('lucid_calm', '1.200000', $t0, $t0->addHours(2));
        $service->createPhase('twilight', '1.000000', $t0->addHours(2), $t0->addHours(4));

        $resp = $this->withToken($plainToken)
            ->getJson('/api/v1/world/phase?at=' . urlencode($t0->addMinutes(30)->toIso8601String()));

        $resp->assertStatus(200)
            ->assertJsonPath('data.key', 'lucid_calm')
            ->assertJsonPath('data.multipliers.fog', '1.200000');

        $service->assertContinuousSchedule($t0, $t0->addHours(4));

        $this->expectException(DomainException::class);
        $service->assertContinuousSchedule($t0, $t0->addHours(5));
    }
}
