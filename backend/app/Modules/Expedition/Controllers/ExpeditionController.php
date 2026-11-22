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
            return $this->apiError('UNAUTHENTICATED', 'Unauthorized.', 401);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:active,completed,claimed,cancelled'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $city = City::query()->where('player_id', $sessionPlayer->id)->first();
        if ($city === null) {
            return response()->json([
                'data' => ['items' => []],
                'meta' => ['request_id' => $this->apiRequestId()],
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
            'meta' => ['request_id' => $this->apiRequestId()],
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return $this->apiError('UNAUTHENTICATED', 'Unauthorized.', 401);
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
            return $this->apiError('RESOURCE_NOT_FOUND', 'City not found.', 404);
        }

        if ((int) $city->player_id !== (int) $sessionPlayer->id) {
            return $this->apiError('FORBIDDEN', 'Forbidden: city does not belong to player.', 403);
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
            $code = str_contains(strtolower($e->getMessage()), 'insufficient')
                ? 'INSUFFICIENT_RESOURCES'
                : 'INVALID_GAME_STATE';

            return $this->apiError($code, $e->getMessage(), 409);
        }

        return response()->json([
            'data' => $expedition,
            'meta' => ['request_id' => $this->apiRequestId()],
        ], 201);
    }

    public function complete(Request $request, Expedition $expedition): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return $this->apiError('UNAUTHENTICATED', 'Unauthorized.', 401);
        }

        $expedition->loadMissing('city');
        if ($expedition->city === null || (int) $expedition->city->player_id !== (int) $sessionPlayer->id) {
            return $this->apiError('FORBIDDEN', 'Forbidden: expedition does not belong to player.', 403);
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
            $code = str_contains(strtolower($e->getMessage()), 'not ready')
                ? 'EXPEDITION_NOT_READY'
                : 'INVALID_GAME_STATE';

            return $this->apiError($code, $e->getMessage(), 409);
        }

        return response()->json([
            'data' => $updated,
            'meta' => ['request_id' => $this->apiRequestId()],
        ]);
    }

    public function claim(Request $request, Expedition $expedition): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return $this->apiError('UNAUTHENTICATED', 'Unauthorized.', 401);
        }

        $expedition->loadMissing('city');
        if ($expedition->city === null || (int) $expedition->city->player_id !== (int) $sessionPlayer->id) {
            return $this->apiError('FORBIDDEN', 'Forbidden: expedition does not belong to player.', 403);
        }

        $validated = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        $idemKey = $this->idempotencyService->resolveKey($request);
        $requestId = $this->apiRequestId();

        return $this->idempotencyService->execute(
            $sessionPlayer,
            $idemKey,
            'expedition_claim_reward',
            [
                'player_id' => $sessionPlayer->id,
                'expedition_id' => $expedition->id,
            ],
            function () use ($expedition, $now, $requestId): array {
                try {
                    $result = $this->expeditionService->claimReward($expedition, $now);
                } catch (RuntimeException $e) {
                    $lower = strtolower($e->getMessage());
                    $code = match (true) {
                        str_contains($lower, 'already claimed') => 'ALREADY_CLAIMED',
                        str_contains($lower, 'not ready') => 'EXPEDITION_NOT_READY',
                        default => 'INVALID_GAME_STATE',
                    };

                    return [
                        'status' => 409,
                        'payload' => [
                            'error' => [
                                'code' => $code,
                                'message' => $e->getMessage(),
                            ],
                            'meta' => ['request_id' => $requestId],
                        ],
                    ];
                }

                return [
                    'status' => 200,
                    'payload' => [
                        'data' => array_merge($result, ['idempotent_replay' => false]),
                        'meta' => ['request_id' => $requestId],
                    ],
                ];
            },
            $now
        );
    }
}
