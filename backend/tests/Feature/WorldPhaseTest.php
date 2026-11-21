<?php

namespace Tests\Feature;

use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WorldPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_gist_prevents_overlapping_phases(): void
    {
        $service = new WorldPhaseService();
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $service->createPhase('calm_tide', '1.000000', $t0, $t0->addHours(2));

        // Смежный интервал [2h, 4h) не пересекается и должен создаться
        $adjacent = $service->createPhase('deep_surge', '1.500000', $t0->addHours(2), $t0->addHours(4));
        $this->assertEquals('deep_surge', $adjacent->phase_name);

        // Пересекающийся интервал [1h, 3h) должен быть отклонён
        $this->expectException(InvalidArgumentException::class);
        $service->createPhase('overlap_phase', '2.000000', $t0->addHour(), $t0->addHours(3));
    }

    public function test_calculate_fog_across_phases_and_baseline(): void
    {
        $service = new WorldPhaseService();
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        // 00:00 - 01:00: базовая фаза (1.0x) -> 100 fog/ч * 1ч = 100
        // 01:00 - 02:00: фаза surge (2.0x) -> 100 fog/ч * 2.0 * 1ч = 200
        $service->createPhase('surge', '2.000000', $t0->addHour(), $t0->addHours(2));

        $result = $service->calculateFogForInterval($t0, $t0->addHours(2), '100.000000');

        $this->assertEquals('300.000000', $result['total_fog']);
        $this->assertCount(2, $result['segments']);
    }
}
