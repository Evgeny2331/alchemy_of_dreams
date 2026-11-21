<?php

namespace App\Modules\Economy\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\OfflineEconomyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class EconomyController extends Controller
{
    public function __construct(
        private readonly OfflineEconomyService $offlineEconomyService
    ) {}

    public function syncFog(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'player_id' => ['nullable', 'integer', 'exists:players,id'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'base_fog_per_hour' => ['required', 'numeric', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'now' => ['nullable', 'date'],
        ]);

        if (isset($validated['player_id']) && (int) $validated['player_id'] !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: player mismatch.'], 403);
        }

        $idempotencyKey = (string) ($validated['idempotency_key']
            ?? $request->header('Idempotency-Key', ''));

        if ($idempotencyKey === '') {
            return response()->json([
                'message' => 'Idempotency key is required.',
            ], 422);
        }

        /** @var City $city */
        $city = City::query()->findOrFail($validated['city_id']);
        if ((int) $city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: city does not belong to player.'], 403);
        }

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $result = $this->offlineEconomyService->syncFogProduction(
                $sessionPlayer,
                $city,
                (string) $validated['base_fog_per_hour'],
                $idempotencyKey,
                $now
            );
        } catch (InvalidArgumentException $e) {
            if (str_contains(strtolower($e->getMessage()), 'idempotency key reused')) {
                throw $e;
            }

            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $result,
        ], $result['idempotent_replay'] ? 200 : 201);
    }
}

