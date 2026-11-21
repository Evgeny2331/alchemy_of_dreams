<?php

namespace App\Modules\WorldPhase\Services;

use App\Modules\Economy\Services\EconomyService;
use App\Modules\WorldPhase\Models\WorldPhase;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class WorldPhaseService
{
    public function __construct(
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    public function createPhase(
        string $phaseName,
        string|int $multiplierFog,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt
    ): WorldPhase {
        $start = CarbonImmutable::instance($startsAt)->utc();
        $end = CarbonImmutable::instance($endsAt)->utc();

        if (!$end->gt($start)) {
            throw new InvalidArgumentException('Phase ends_at must be strictly greater than starts_at.');
        }

        $economy = $this->economy();
        $formattedMultiplier = $economy->normalizeDecimal((string) $multiplierFog, 6);

        if (str_starts_with($formattedMultiplier, '-')) {
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
                'multiplier_fog' => $formattedMultiplier,
                'starts_at' => $start,
                'ends_at' => $end,
            ]);
        } catch (QueryException $e) {
            throw new InvalidArgumentException('World phase overlaps with an existing phase.', 0, $e);
        }
    }

    public function getActivePhaseAt(?CarbonInterface $at = null): ?WorldPhase
    {
        $point = CarbonImmutable::instance($at ?? CarbonImmutable::now('UTC'))->utc();

        return WorldPhase::query()
            ->where('starts_at', '<=', $point)
            ->where('ends_at', '>', $point)
            ->orderBy('starts_at')
            ->first();
    }

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

    public function calculateFogForInterval(
        CarbonInterface $from,
        CarbonInterface $to,
        string|int $baseFogPerHour
    ): array {
        $start = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();

        if ($end->lt($start)) {
            throw new InvalidArgumentException('Interval end must not be earlier than start.');
        }

        $economy = $this->economy();
        $baseRate = $economy->normalizeDecimal((string) $baseFogPerHour, 6);
        if (str_starts_with($baseRate, '-')) {
            throw new InvalidArgumentException('baseFogPerHour must be non-negative.');
        }

        if ($start->equalTo($end) || $baseRate === '0.000000') {
            return [
                'total_fog' => '0.000000',
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
        $totalScaled = 0;
        $baseScaled = $this->toScaledInt($baseRate, 6);
        $defaultMultFormatted = '1.000000';
        $defaultMultScaled = 1_000_000;

        foreach ($phases as $phase) {
            $pStart = CarbonImmutable::instance($phase->starts_at)->utc();
            $pEnd = CarbonImmutable::instance($phase->ends_at)->utc();

            if ($pStart->gt($cursor)) {
                $segEnd = $pStart->lt($end) ? $pStart : $end;
                $seconds = $segEnd->getTimestamp() - $cursor->getTimestamp();
                if ($seconds > 0) {
                    $earnedScaled = $this->computeSegmentScaled($baseScaled, $defaultMultScaled, $seconds);
                    $totalScaled += $earnedScaled;
                    $segments[] = [
                        'from' => $cursor->toIso8601String(),
                        'to' => $segEnd->toIso8601String(),
                        'seconds' => $seconds,
                        'phase_name' => null,
                        'multiplier_fog' => $defaultMultFormatted,
                        'fog_earned' => $this->fromScaledInt($earnedScaled, 6),
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
                $multFormatted = $economy->normalizeDecimal((string) $phase->multiplier_fog, 6);
                $multScaled = $this->toScaledInt($multFormatted, 6);
                $earnedScaled = $this->computeSegmentScaled($baseScaled, $multScaled, $seconds);
                $totalScaled += $earnedScaled;

                $segments[] = [
                    'from' => $activeStart->toIso8601String(),
                    'to' => $activeEnd->toIso8601String(),
                    'seconds' => $seconds,
                    'phase_name' => $phase->phase_name,
                    'multiplier_fog' => $multFormatted,
                    'fog_earned' => $this->fromScaledInt($earnedScaled, 6),
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
                $earnedScaled = $this->computeSegmentScaled($baseScaled, $defaultMultScaled, $seconds);
                $totalScaled += $earnedScaled;
                $segments[] = [
                    'from' => $cursor->toIso8601String(),
                    'to' => $end->toIso8601String(),
                    'seconds' => $seconds,
                    'phase_name' => null,
                    'multiplier_fog' => $defaultMultFormatted,
                    'fog_earned' => $this->fromScaledInt($earnedScaled, 6),
                ];
            }
        }

        return [
            'total_fog' => $this->fromScaledInt($totalScaled, 6),
            'segments' => $segments,
        ];
    }

    private function computeSegmentScaled(int $baseScaled, int $multScaled, int $seconds): int
    {
        // baseScaled (10^6) * multScaled (10^6) * seconds / (3600 * 10^6) -> масштаб 10^6
        $numerator = $baseScaled * $multScaled * $seconds;
        $denominator = 3600 * 1_000_000;
        return (int) intdiv(($numerator + intdiv($denominator, 2)), $denominator);
    }

    private function toScaledInt(string $unsignedNormalized, int $scale): int
    {
        [$intPart, $fracPart] = array_pad(explode('.', $unsignedNormalized, 2), 2, '');
        return (int) ($intPart . str_pad($fracPart, $scale, '0', STR_PAD_RIGHT));
    }

    private function fromScaledInt(int $scaled, int $scale): string
    {
        $factor = 10 ** $scale;
        $intPart = intdiv($scaled, $factor);
        $fracPart = str_pad((string) ($scaled % $factor), $scale, '0', STR_PAD_LEFT);
        return $intPart . '.' . $fracPart;
    }
}
