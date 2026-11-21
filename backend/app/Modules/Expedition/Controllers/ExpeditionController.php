<?php

namespace App\Modules\Expedition\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\IdempotencyService;
use App\Modules\Expedition\Models\Expedition;
use App\Modules\Expedition\Services\ExpeditionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ExpeditionController extends Controller
{
    public function __construct(
        private readonly ExpeditionService $expeditionService,
        private readonly IdempotencyService $idempotencyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:active,completed,claimed,cancelled'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $city = City::query()->where('player_id', $sessionPlayer->id)->first();
        if ($city === null) {
            return response()->json([
                'data' => ['items' => []],
                'meta' => ['request_id' => (string) Str::uuid()],
            ]);
        }

        $query = Expedition::query()
            ->where('city_id', $city->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if (!empty($validated['status'])) {
            $query->where('status', (string) $validated['status']);
        }

        $limit = (int) ($validated['limit'] ?? 20);
        $items = $query->limit($limit)->get();

        return response()->json([
            'data' => [
                'items' => $items,
            ],
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'expedition_type' => ['nullable', 'string', 'in:surface,deep'],
            'type' => ['nullable', 'string', 'in:surface,deep'],
            'resident_ids' => ['required', 'array', 'min:1'],
            'resident_ids.*' => ['required', 'integer', 'distinct'],
            'balance_version' => ['nullable', 'string', 'max:32'],
            'now' => ['nullable', 'date'],
        ]);

        /** @var City|null $city */
        $city = isset($validated['city_id'])
            ? City::query()->findOrFail($validated['city_id'])
            : City::query()->where('player_id', $sessionPlayer->id)->first();

        if ($city === null) {
            return response()->json(['message' => 'City not found.'], 404);
        }

        if ((int) $city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: city does not belong to player.'], 403);
        }

        $expeditionType = (string) ($validated['expedition_type'] ?? $validated['type'] ?? 'surface');

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $expedition = $this->expeditionService->startExpedition(
                $city,
                $expeditionType,
                array_map('intval', $validated['resident_ids']),
                (string) ($validated['balance_version'] ?? '1.0'),
                $now
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $expedition,
            'meta' => ['request_id' => (string) Str::uuid()],
        ], 201);
    }

    public function complete(Request $request, Expedition $expedition): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $expedition->loadMissing('city');
        if ($expedition->city === null || (int) $expedition->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: expedition does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $updated = $this->expeditionService->completeExpedition($expedition, $now);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $updated,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function claim(Request $request, Expedition $expedition): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $expedition->loadMissing('city');
        if ($expedition->city === null || (int) $expedition->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: expedition does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        $idemKey = $this->idempotencyService->resolveKey($request);

        return $this->idempotencyService->execute(
            $sessionPlayer,
            $idemKey,
            'expedition_claim_reward',
            [
                'player_id' => $sessionPlayer->id,
                'expedition_id' => $expedition->id,
            ],
            function () use ($expedition, $now): array {
                try {
                    $result = $this->expeditionService->claimReward($expedition, $now);
                } catch (RuntimeException $e) {
                    return [
                        'status' => 422,
                        'payload' => ['message' => $e->getMessage()],
                    ];
                }

                return [
                    'status' => 200,
                    'payload' => [
                        'data' => array_merge($result, ['idempotent_replay' => false]),
                        'meta' => ['request_id' => (string) Str::uuid()],
                    ],
                ];
            },
            $now
        );
    }
}
