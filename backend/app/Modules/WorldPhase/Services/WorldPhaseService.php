<?php

namespace App\Modules\WorldPhase\Services;

use App\Modules\WorldPhase\Models\WorldPhase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class WorldPhaseService
{
    /**
     * Создаёт фазу мира; пересечение интервалов предотвращается GIST-ограничением и проверкой.
     */
    public function createPhase(
        string $phaseName,
        string|int|float $multiplierFog,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt
    ): WorldPhase {
        $start = CarbonImmutable::instance($startsAt)->utc();
        $end = CarbonImmutable::instance($endsAt)->utc();

        if (!$end->gt($start)) {
            throw new InvalidArgumentException('Phase ends_at must be strictly greater than starts_at.');
        }

        if ((float) $multiplierFog < 0) {
            throw new InvalidArgumentException('multiplier_fog must be non-negative.');
        }

        $overlapping = WorldPhase::query()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();

        if ($overlapping) {
            throw new InvalidArgumentException('World phase overlaps with an existing phase.');
        }

        try {
            return WorldPhase::create([
                'phase_name' => $phaseName,
                'multiplier_fog' => number_format((float) $multiplierFog, 6, '.', ''),
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        } catch (QueryException $e) {
            throw new InvalidArgumentException('World phase overlaps with an existing phase.', 0, $e);
        }
    }

    /**
     * Возвращает фазу мира, активную в указанный момент времени (или null).
     */
    public function getActivePhaseAt(?CarbonInterface $at = null): ?WorldPhase
    {
        $point = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();

        /** @var ?WorldPhase */
        return WorldPhase::query()
            ->where('starts_at', '<=', $point)
            ->where('ends_at', '>', $point)
            ->orderBy('starts_at')
            ->first();
    }

    /**
     * Проверяет непрерывность расписания фаз на интервале [from, to).
     * Выбрасывает DomainException при наличии пробела.
     */
    public function assertContinuousSchedule(CarbonInterface $from, CarbonInterface $to): void
    {
        $start = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();

        if (!$end->gt($start)) {
            return;
        }

        $phases = WorldPhase::query()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        if ($phases->isEmpty()) {
            throw new DomainException('World phase schedule gap detected: no phases cover interval.');
        }

        $cursor = $start;
        foreach ($phases as $phase) {
            $pStart = CarbonImmutable::instance($phase->starts_at)->utc();
            $pEnd = CarbonImmutable::instance($phase->ends_at)->utc();

            if ($pStart->gt($cursor)) {
                throw new DomainException('World phase schedule gap detected between intervals.');
            }

            if ($pEnd->gt($cursor)) {
                $cursor = $pEnd->lt($end) ? $pEnd : $end;
            }

            if ($cursor->gte($end)) {
                break;
            }
        }

        if ($cursor->lt($end)) {
            throw new DomainException('World phase schedule gap detected before interval end.');
        }
    }

    /**
     * Разбивает интервал [from, to) на сегменты с учётом фаз мира и базовой скорости fog/час.
     *
     * @return array{
     *   total_fog: string,
     *   segments: list<array{
     *     from: string,
     *     to: string,
     *     seconds: int,
     *     phase_name: ?string,
     *     multiplier_fog: string,
     *     fog_earned: string
     *   }>
     * }
     */
    public function calculateFogForInterval(
        CarbonInterface $from,
        CarbonInterface $to,
        string|int|float $baseFogPerHour
    ): array {
        $start = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Interval end must not be earlier than start.');
        }

        $baseRate = (float) $baseFogPerHour;
        if ($baseRate < 0 || $start->equalTo($end)) {
            return [
                'total_fog' => number_format(0, 6, '.', ''),
                'segments' => [],
            ];
        }

        $phases = WorldPhase::query()
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        $cursor = $start;
        $segments = [];
        $totalFog = 0.0;

        foreach ($phases as $phase) {
            $pStart = CarbonImmutable::instance($phase->starts_at)->utc();
            $pEnd = CarbonImmutable::instance($phase->ends_at)->utc();

            if ($pStart->gt($cursor)) {
                $segEnd = $pStart->lt($end) ? $pStart : $end;
                $seconds = $segEnd->getTimestamp() - $cursor->getTimestamp();
                if ($seconds > 0) {
                    $earned = ($baseRate * 1.0) * ($seconds / 3600.0);
                    $totalFog += $earned;
                    $segments[] = [
                        'from' => $cursor->toIso8601String(),
                        'to' => $segEnd->toIso8601String(),
                        'seconds' => $seconds,
                        'phase_name' => null,
                        'multiplier_fog' => number_format(1.0, 6, '.', ''),
                        'fog_earned' => number_format($earned, 6, '.', ''),
                    ];
                }
                $cursor = $segEnd;
            }

            if ($cursor->gte($end)) {
                break;
            }

            $activeStart = $pStart->gt($cursor) ? $pStart : $cursor;
            $activeEnd = $pEnd->lt($end) ? $pEnd : $end;
            $seconds = $activeEnd->getTimestamp() - $activeStart->getTimestamp();

            if ($seconds > 0) {
                $mult = (float) $phase->multiplier_fog;
                $earned = ($baseRate * $mult) * ($seconds / 3600.0);
                $totalFog += $earned;
                $segments[] = [
                    'from' => $activeStart->toIso8601String(),
                    'to' => $activeEnd->toIso8601String(),
                    'seconds' => $seconds,
                    'phase_name' => $phase->phase_name,
                    'multiplier_fog' => number_format($mult, 6, '.', ''),
                    'fog_earned' => number_format($earned, 6, '.', ''),
                ];
                $cursor = $activeEnd;
            }

            if ($cursor->gte($end)) {
                break;
            }
        }

        if ($cursor->lt($end)) {
            $seconds = $end->getTimestamp() - $cursor->getTimestamp();
            if ($seconds > 0) {
                $earned = ($baseRate * 1.0) * ($seconds / 3600.0);
                $totalFog += $earned;
                $segments[] = [
                    'from' => $cursor->toIso8601String(),
                    'to' => $end->toIso8601String(),
                    'seconds' => $seconds,
                    'phase_name' => null,
                    'multiplier_fog' => number_format(1.0, 6, '.', ''),
                    'fog_earned' => number_format($earned, 6, '.', ''),
                ];
            }
        }

        return [
            'total_fog' => number_format($totalFog, 6, '.', ''),
            'segments' => $segments,
        ];
    }
}
