<?php

namespace App\Modules\WorldPhase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WorldPhaseController extends Controller
{
    public function __construct(
        private readonly WorldPhaseService $worldPhaseService
    ) {}

    public function current(Request $request): JsonResponse
    {
        $now = $request->query('at')
            ? CarbonImmutable::parse((string) $request->query('at'))->utc()
            : CarbonImmutable::now('UTC');

        $phase = $this->worldPhaseService->getActivePhaseAt($now);

        if ($phase === null) {
            return response()->json([
                'error' => [
                    'code' => 'WORLD_PHASE_UNAVAILABLE',
                    'message' => 'No active world phase scheduled for the requested timestamp.',
                ],
                'meta' => [
                    'request_id' => (string) Str::uuid(),
                ],
            ], 503);
        }

        return response()->json([
            'data' => [
                'key' => $phase->phase_name,
                'starts_at' => CarbonImmutable::instance($phase->starts_at)->utc()->toIso8601String(),
                'ends_at' => CarbonImmutable::instance($phase->ends_at)->utc()->toIso8601String(),
                'multipliers' => [
                    'fog' => number_format((float) $phase->multiplier_fog, 6, '.', ''),
                ],
                'server_time' => $now->toIso8601String(),
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'balance_version' => '1.0',
            ],
        ]);
    }
}
