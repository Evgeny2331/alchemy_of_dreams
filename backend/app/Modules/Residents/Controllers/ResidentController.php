<?php

namespace App\Modules\Residents\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\City;
use App\Modules\Residents\Models\Resident;
use App\Modules\Residents\Models\ResidentRequest;
use App\Modules\Residents\Services\ResidentRequestService;
use App\Modules\Residents\Services\ResidentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ResidentController extends Controller
{
    public function __construct(
        private readonly ResidentService $residentService,
        private readonly ResidentRequestService $residentRequestService
    ) {}

    public function listByCity(Request $request, City $city): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        if ((int) $city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: city does not belong to player.'], 403);
        }

        $residents = Resident::query()
            ->where('city_id', $city->id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $residents,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function assign(Request $request, Resident $resident): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $resident->loadMissing('city');
        if ($resident->city === null || (int) $resident->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: resident does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
        ]);

        /** @var Building $building */
        $building = Building::query()->findOrFail($validated['building_id']);

        try {
            $updated = $this->residentService->assignToBuilding($resident, $building);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $updated,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function unassign(Request $request, Resident $resident): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $resident->loadMissing('city');
        if ($resident->city === null || (int) $resident->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: resident does not belong to player.'], 403);
        }

        try {
            $updated = $this->residentService->unassign($resident);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $updated,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function generateRequest(Request $request, Resident $resident): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $resident->loadMissing('city');
        if ($resident->city === null || (int) $resident->city->player_id !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: resident does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $created = $this->residentRequestService->generateRequest($resident, $now);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $created,
            'meta' => ['request_id' => (string) Str::uuid()],
        ], 201);
    }

    public function completeRequest(Request $request, ResidentRequest $residentRequest): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $residentRequest->loadMissing('resident.city');
        $ownerId = $residentRequest->resident?->city?->player_id;
        if ($ownerId === null || (int) $ownerId !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: request does not belong to player.'], 403);
        }

        $validated = $request->validate([
            'now' => ['nullable', 'date'],
        ]);

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        try {
            $completed = $this->residentRequestService->completeRequest($residentRequest, $now);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $completed,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function declineRequest(Request $request, ResidentRequest $residentRequest): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $residentRequest->loadMissing('resident.city');
        $ownerId = $residentRequest->resident?->city?->player_id;
        if ($ownerId === null || (int) $ownerId !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: request does not belong to player.'], 403);
        }

        try {
            $declined = $this->residentRequestService->declineRequest($residentRequest);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $declined,
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }
}
