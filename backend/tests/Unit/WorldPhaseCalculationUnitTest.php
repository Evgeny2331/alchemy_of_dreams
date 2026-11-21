<?php

namespace Tests\Unit;

use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WorldPhaseCalculationUnitTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_fog_and_schedule_continuity_edge_cases(): void
    {
        $service = new WorldPhaseService();
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        // Нулевой интервал возвращает 0.000000 и пустой список сегментов
        $zero = $service->calculateFogForInterval($t0, $t0, '50.000000');
        $this->assertSame('0.000000', $zero['total_fog']);
        $this->assertSame([], $zero['segments']);

        // Конец раньше начала выбрасывает InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $service->calculateFogForInterval($t0->addHour(), $t0, '50.000000');
    }

    public function test_segment_splitting_with_baseline_and_phase_multipliers(): void
    {
        $service = new WorldPhaseService();
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        // 00:00..01:00 -> базовый множитель 1.0 * 100 = 100
        // 01:00..02:00 -> фаза 1.5 * 100 = 150
        // 02:00..03:00 -> базовый множитель 1.0 * 100 = 100
        $service->createPhase('lucid_wave', '1.500000', $t0->addHour(), $t0->addHours(2));

        $result = $service->calculateFogForInterval($t0, $t0->addHours(3), '100.000000');
        $this->assertSame('350.000000', $result['total_fog']);
        $this->assertCount(3, $result['segments']);

        // Непрерывность расписания на [t0, t0+2h) не выполняется из-за пробела 00:00..01:00
        $this->expectException(DomainException::class);
        $service->assertContinuousSchedule($t0, $t0->addHours(2));
    }
}
