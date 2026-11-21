<?php

namespace App\Modules\City\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\City\Services\CityService;
use App\Modules\Economy\Services\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CityController extends Controller
{
    public function __construct(
        private readonly CityService $cityService,
        private readonly IdempotencyService $idempotencyService
    ) {}

    public function state(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        /** @var City|null $city */
        $city = City::query()
            ->where('player_id', $sessionPlayer->id)
            ->with([
                'islands' => fn ($q) => $q->orderBy('island_index'),
                'buildings' => fn ($q) => $q->orderBy('id'),
            ])
            ->first();

        $resources = $sessionPlayer->resources()
            ->orderBy('resource_type')
            ->get(['resource_type', 'balance', 'updated_at']);

        return response()->json([
            'data' => [
                'player' => [
                    'id' => $sessionPlayer->id,
                    'telegram_id' => $sessionPlayer->telegram_id,
                    'username' => $sessionPlayer->username,
                    'first_name' => $sessionPlayer->first_name,
                ],
                'city' => $city,
                'resources' => $resources,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'balance_version' => '1.0',
            ],
        ]);
    }

    public function catalog(): JsonResponse
    {
        $buildings = BuildingCatalog::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => [
                'buildings' => $buildings,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'balance_version' => '1.0',
            ],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'building_key' => ['required', 'string', 'max:64'],
            'island_id' => ['required', 'integer', 'exists:islands,id'],
            'position' => ['nullable', 'array'],
            'position.x' => ['required_with:position', 'integer', 'min:0'],
            'position.y' => ['required_with:position', 'integer', 'min:0'],
            'position_x' => ['nullable', 'integer', 'min:0'],
            'position_y' => ['nullable', 'integer', 'min:0'],
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

        $posX = (int) ($validated['position']['x'] ?? $validated['position_x'] ?? 0);
        $posY = (int) ($validated['position']['y'] ?? $validated['position_y'] ?? 0);

        try {
            $preview = $this->cityService->previewBuilding(
                $city,
                (int) $validated['island_id'],
                (string) $validated['building_key'],
                $posX,
                $posY
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $preview,
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'balance_version' => '1.0',
            ],
        ]);
    }

    public function unlockIsland(Request $request, Island $island): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $island->loadMissing('city');
        if ($island->city === null || (int) $island->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: island does not belong to player.'], 403);
        }

        try {
            $result = $this->cityService->unlockIsland($island);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => $result,
            'meta' => [
                'request_id' => (string) Str::uuid(),
            ],
        ]);
    }

    public function build(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'island_id' => ['required', 'integer', 'exists:islands,id'],
            'building_key' => ['required', 'string', 'max:64'],
            'position' => ['nullable', 'array'],
            'position.x' => ['required_with:position', 'integer', 'min:0'],
            'position.y' => ['required_with:position', 'integer', 'min:0'],
            'position_x' => ['nullable', 'integer', 'min:0'],
            'position_y' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
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

        $posX = (int) ($validated['position']['x'] ?? $validated['position_x'] ?? 0);
        $posY = (int) ($validated['position']['y'] ?? $validated['position_y'] ?? 0);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        $idemKey = $this->idempotencyService->resolveKey($request);

        return $this->idempotencyService->execute(
            $sessionPlayer,
            $idemKey,
            'city_build_building',
            [
                'player_id' => $sessionPlayer->id,
                'city_id' => $city->id,
                'island_id' => (int) $validated['island_id'],
                'building_key' => (string) $validated['building_key'],
                'position_x' => $posX,
                'position_y' => $posY,
            ],
            function () use ($city, $validated, $posX, $posY, $now): array {
                try {
                    $result = $this->cityService->buildBuilding(
                        $city,
                        (int) $validated['island_id'],
                        (string) $validated['building_key'],
                        $posX,
                        $posY,
                        $now
                    );
                } catch (InvalidArgumentException|RuntimeException $e) {
                    return [
                        'status' => 422,
                        'payload' => ['message' => $e->getMessage()],
                    ];
                }

                return [
                    'status' => 201,
                    'payload' => [
                        'data' => array_merge($result, ['idempotent_replay' => false]),
                        'meta' => ['request_id' => (string) Str::uuid()],
                    ],
                ];
            },
            $now
        );
    }

    public function upgrade(Request $request, Building $building): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $building->loadMissing('city');
        if ($building->city === null || (int) $building->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: building does not belong to player.'], 403);
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
            'city_upgrade_building',
            [
                'player_id' => $sessionPlayer->id,
                'building_id' => $building->id,
            ],
            function () use ($building, $now): array {
                try {
                    $result = $this->cityService->upgradeBuilding($building, $now);
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

    public function move(Request $request, Building $building): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $building->loadMissing('city');
        if ($building->city === null || (int) $building->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: building does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'island_id' => ['required', 'integer', 'exists:islands,id'],
            'position' => ['nullable', 'array'],
            'position.x' => ['required_with:position', 'integer', 'min:0'],
            'position.y' => ['required_with:position', 'integer', 'min:0'],
            'position_x' => ['nullable', 'integer', 'min:0'],
            'position_y' => ['nullable', 'integer', 'min:0'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ]);

        $targetX = (int) ($validated['position']['x'] ?? $validated['position_x'] ?? 0);
        $targetY = (int) ($validated['position']['y'] ?? $validated['position_y'] ?? 0);
        $idemKey = $this->idempotencyService->resolveKey($request);

        return $this->idempotencyService->execute(
            $sessionPlayer,
            $idemKey,
            'city_move_building',
            [
                'player_id' => $sessionPlayer->id,
                'building_id' => $building->id,
                'island_id' => (int) $validated['island_id'],
                'position_x' => $targetX,
                'position_y' => $targetY,
            ],
            function () use ($building, $validated, $targetX, $targetY): array {
                try {
                    $updated = $this->cityService->moveBuilding(
                        $building,
                        (int) $validated['island_id'],
                        $targetX,
                        $targetY
                    );
                } catch (InvalidArgumentException|RuntimeException $e) {
                    return [
                        'status' => 422,
                        'payload' => ['message' => $e->getMessage()],
                    ];
                }

                return [
                    'status' => 200,
                    'payload' => [
                        'data' => [
                            'building_id' => $updated->id,
                            'island_id' => $updated->island_id,
                            'position' => [
                                'x' => $updated->position_x,
                                'y' => $updated->position_y,
                            ],
                            'building' => $updated,
                            'idempotent_replay' => false,
                        ],
                        'meta' => ['request_id' => (string) Str::uuid()],
                    ],
                ];
            }
        );
    }

    public function demolish(Request $request, Building $building): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $building->loadMissing('city');
        if ($building->city === null || (int) $building->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: building does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $updated = $this->cityService->demolishBuilding($building, $now);
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
}
