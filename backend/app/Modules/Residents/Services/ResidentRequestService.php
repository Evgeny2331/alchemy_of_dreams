<?php

namespace App\Modules\Residents\Services;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\EconomyService;
use App\Modules\Residents\Models\Resident;
use App\Modules\Residents\Models\ResidentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ResidentRequestService
{
    public const COOLDOWN_HOURS = 6;
    public const MAX_COST_RATIO = 0.15;

    public function __construct(
        private readonly EconomyService $economyService
    ) {}

    /**
     * Генерирует новый запрос жителя с учетом частоты запросов и лимита стоимости <= 15% запаса.
     */
    public function generateRequest(Resident $resident, ?CarbonImmutable $now = null): ResidentRequest
    {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($resident, $timestamp): ResidentRequest {
            /** @var Resident $lockedResident */
            $lockedResident = Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var City $city */
            $city = City::query()->whereKey($lockedResident->city_id)->firstOrFail();
            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            $activeExists = ResidentRequest::query()
                ->where('resident_id', $lockedResident->id)
                ->where('status', 'available')
                ->exists();

            if ($activeExists) {
                throw new RuntimeException('Resident already has an available request.');
            }

            $latest = ResidentRequest::query()
                ->where('resident_id', $lockedResident->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if ($latest !== null) {
                $nextAvailableAt = CarbonImmutable::instance($latest->created_at)
                    ->utc()
                    ->addHours(self::COOLDOWN_HOURS);

                if ($timestamp->lt($nextAvailableAt)) {
                    throw new RuntimeException('Resident request cooldown is still active.');
                }
            }

            $resourceType = match ($lockedResident->archetype) {
                'phobia' => 'silence',
                'memory' => 'memories',
                default => 'fog',
            };

            $balanceRow = DB::table('player_resources')
                ->where('player_id', $player->id)
                ->where('resource_type', $resourceType)
                ->first();

            $currentBalance = $balanceRow !== null ? (float) $balanceRow->balance : 0.0;
            $maxAllowedCost = round($currentBalance * self::MAX_COST_RATIO, 6);
            $desiredCost = min(15.0, round($currentBalance * 0.10, 6));
            $finalCost = $maxAllowedCost > 0 ? min($desiredCost, $maxAllowedCost) : 0.0;

            $costPayload = $finalCost > 0
                ? [
                    'resource_type' => $resourceType,
                    'amount' => number_format($finalCost, 6, '.', ''),
                ]
                : [];

            $rewardPayload = [
                'resource_type' => 'memories',
                'amount' => number_format(5.0, 6, '.', ''),
                'trust_delta' => 1,
            ];

            return ResidentRequest::create([
                'resident_id' => $lockedResident->id,
                'request_type' => "archetype_{$lockedResident->archetype}_offering",
                'cost_payload' => $costPayload,
                'reward_payload' => $rewardPayload,
                'status' => 'available',
                'available_at' => $timestamp,
                'expires_at' => $timestamp->addHours(24),
                'completed_at' => null,
                'created_at' => $timestamp,
            ]);
        });
    }

    /**
     * Выполняет доступный запрос жителя: списывает стоимость, начисляет награду и повышает доверие.
     */
    public function completeRequest(ResidentRequest $request, ?CarbonImmutable $now = null): ResidentRequest
    {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($request, $timestamp): ResidentRequest {
            /** @var ResidentRequest $locked */
            $locked = ResidentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'available') {
                throw new RuntimeException("Request cannot be completed in status: {$locked->status}.");
            }

            if ($locked->expires_at !== null && $timestamp->gte(CarbonImmutable::instance($locked->expires_at)->utc())) {
                $locked->update(['status' => 'expired']);
                throw new RuntimeException('Resident request has expired.');
            }

            /** @var Resident $resident */
            $resident = Resident::query()
                ->whereKey($locked->resident_id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var City $city */
            $city = City::query()->whereKey($resident->city_id)->firstOrFail();
            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            $cost = $locked->cost_payload ?? [];
            if (!empty($cost['resource_type']) && isset($cost['amount']) && (float) $cost['amount'] > 0) {
                $this->economyService->applyResourceDelta(
                    $player,
                    (string) $cost['resource_type'],
                    number_format(-(float) $cost['amount'], 6, '.', ''),
                    'resident_request_cost',
                    "resident_req_cost:{$locked->id}:" . (string) Str::uuid(),
                    'resident_request',
                    $locked->id,
                    $timestamp
                );
            }

            $reward = $locked->reward_payload ?? [];
            if (!empty($reward['resource_type']) && isset($reward['amount']) && (float) $reward['amount'] > 0) {
                $this->economyService->applyResourceDelta(
                    $player,
                    (string) $reward['resource_type'],
                    number_format((float) $reward['amount'], 6, '.', ''),
                    'resident_request_reward',
                    "resident_req_reward:{$locked->id}:" . (string) Str::uuid(),
                    'resident_request',
                    $locked->id,
                    $timestamp
                );
            }

            $trustDelta = (int) ($reward['trust_delta'] ?? 1);
            $resident->update([
                'trust_level' => (int) $resident->trust_level + max(0, $trustDelta),
            ]);

            $locked->update([
                'status' => 'completed',
                'completed_at' => $timestamp,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Отклоняет доступный запрос жителя без блокировки основного прогресса.
     */
    public function declineRequest(ResidentRequest $request): ResidentRequest
    {
        return DB::transaction(function () use ($request): ResidentRequest {
            /** @var ResidentRequest $locked */
            $locked = ResidentRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'available') {
                throw new RuntimeException("Request cannot be declined in status: {$locked->status}.");
            }

            $locked->update([
                'status' => 'declined',
            ]);

            return $locked->fresh();
        });
    }
}

