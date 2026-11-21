<?php

namespace App\Modules\Economy\Services;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OfflineEconomyService
{
    public const BASE_STORAGE_CAPACITY = 200.0;

    public function __construct(
        private readonly WorldPhaseService $worldPhaseService,
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    /**
     * Вычисляет базовую скорость производства Fog в час для города
     * как сумму fog_per_hour по всем активным зданиям (status = 'active') по их текущему уровню.
     */
    public function calculateCityBaseFogPerHour(City $city): float
    {
        if (!Schema::hasTable('buildings') || !Schema::hasTable('building_level_configs')) {
            return 0.0;
        }

        $sum = DB::table('buildings as b')
            ->join('building_level_configs as blc', function ($join): void {
                $join->on('blc.building_catalog_id', '=', 'b.building_catalog_id')
                    ->on('blc.level', '=', 'b.level');
            })
            ->where('b.city_id', $city->id)
            ->where('b.status', 'active')
            ->sum('blc.fog_per_hour');

        return round(max(0.0, (float) $sum), 6);
    }

    /**
     * Вычисляет текущую вместимость хранилища Fog для города:
     * BaseStorage (200) + сумма storage_capacity активных зданий по их уровню.
     */
    public function calculateFogStorageCap(City $city): float
    {
        $extraCapacity = 0.0;

        if (Schema::hasTable('buildings') && Schema::hasTable('building_level_configs')) {
            $sum = DB::table('buildings as b')
                ->join('building_level_configs as blc', function ($join): void {
                    $join->on('blc.building_catalog_id', '=', 'b.building_catalog_id')
                        ->on('blc.level', '=', 'b.level');
                })
                ->where('b.city_id', $city->id)
                ->where('b.status', 'active')
                ->sum('blc.storage_capacity');

            $extraCapacity = (float) $sum;
        }

        return round(self::BASE_STORAGE_CAPACITY + max(0.0, $extraCapacity), 6);
    }

    /**
     * Выполняет экономический checkpoint за интервал [lockedCity.economy_calculated_at, targetTime)
     * по текущим активным зданиям города и фазам мира в рамках уже открытой транзакции.
     *
     * @return array{
     *   fog_earned: string,
     *   storage_cap: string,
     *   economy_calculated_at: string,
     *   segments: list<array<string, mixed>>
     * }
     */
    public function checkpointLockedCityFog(
        Player $player,
        City $lockedCity,
        ?float $overrideBaseFogPerHour,
        string $idempotencyKey,
        CarbonImmutable $targetTime
    ): array {
        $this->economy()->assertValidResourceType('fog');

        $fromTime = CarbonImmutable::instance($lockedCity->economy_calculated_at)->utc();
        $effectiveTargetTime = $targetTime->lt($fromTime) ? $fromTime : $targetTime;

        $baseFogPerHour = $overrideBaseFogPerHour ?? $this->calculateCityBaseFogPerHour($lockedCity);
        $calc = $this->worldPhaseService->calculateFogForInterval($fromTime, $effectiveTargetTime, $baseFogPerHour);
        $rawFogEarned = round((float) $calc['total_fog'], 6);
        $storageCap = $this->calculateFogStorageCap($lockedCity);

        $existingResource = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->lockForUpdate()
            ->first();

        $currentBalance = $existingResource !== null ? round((float) $existingResource->balance, 6) : 0.0;
        $availableCapacity = max(0.0, round($storageCap - $currentBalance, 6));
        $actualFogEarned = round(min($rawFogEarned, $availableCapacity), 6);
        $fogFormatted = number_format($actualFogEarned, 6, '.', '');

        if ($actualFogEarned > 0) {
            $newBalanceFormatted = number_format(round($currentBalance + $actualFogEarned, 6), 6, '.', '');

            if ($existingResource !== null) {
                DB::table('player_resources')
                    ->where('id', $existingResource->id)
                    ->update([
                        'balance' => $newBalanceFormatted,
                        'updated_at' => $targetTime,
                    ]);
            } else {
                DB::table('player_resources')->insert([
                    'player_id' => $player->id,
                    'resource_type' => 'fog',
                    'balance' => $newBalanceFormatted,
                    'created_at' => $targetTime,
                    'updated_at' => $targetTime,
                ]);
            }

            $ledgerPayload = [
                'player_id' => $player->id,
                'resource_type' => 'fog',
                'amount' => $fogFormatted,
                'operation_type' => 'offline_production',
                'idempotency_key' => $idempotencyKey,
                'created_at' => $targetTime,
            ];

            $columns = DB::getSchemaBuilder()->getColumnListing('resource_ledgers');
            if (in_array('reference_type', $columns, true)) {
                $ledgerPayload['reference_type'] = 'city';
            }
            if (in_array('reference_id', $columns, true)) {
                $ledgerPayload['reference_id'] = $lockedCity->id;
            }
            if (in_array('transaction_id', $columns, true)) {
                $ledgerPayload['transaction_id'] = (string) Str::uuid();
            }
            if (in_array('updated_at', $columns, true)) {
                $ledgerPayload['updated_at'] = $targetTime;
            }

            DB::table('resource_ledgers')->insert($ledgerPayload);
        }

        $lockedCity->update([
            'economy_calculated_at' => $effectiveTargetTime,
        ]);

        return [
            'fog_earned' => $fogFormatted,
            'storage_cap' => number_format($storageCap, 6, '.', ''),
            'economy_calculated_at' => $targetTime->toIso8601String(),
            'segments' => $calc['segments'],
        ];
    }

    /**
     * Начисляет оффлайн-доход fog за интервал [city.economy_calculated_at, now)
     * с учётом фаз мира и лимита вместимости хранилища, обновляет economy_calculated_at,
     * ledger и transaction_outbox. Поддерживает идемпотентность по player_id + idempotency_key.
     *
     * @return array{
     *   idempotent_replay: bool,
     *   fog_earned: string,
     *   storage_cap: string,
     *   economy_calculated_at: string,
     *   segments: list<array<string, mixed>>
     * }
     */
    public function syncFogProduction(
        Player $player,
        City $city,
        string $baseFogPerHour,
        string $idempotencyKey,
        ?CarbonInterface $now = null
    ): array {
        if ($city->player_id !== $player->id) {
            throw new InvalidArgumentException('City does not belong to the specified player.');
        }

        $this->economy()->assertValidResourceType('fog');

        $targetTime = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'))->utc();
        $requestHash = hash('sha256', json_encode([
            'player_id' => $player->id,
            'city_id' => $city->id,
            'base_fog_per_hour' => number_format((float) $baseFogPerHour, 6, '.', ''),
            'target_time' => $targetTime->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $player,
            $city,
            $baseFogPerHour,
            $idempotencyKey,
            $requestHash,
            $targetTime
        ): array {
            $existingRecord = DB::table('idempotency_records')
                ->where('player_id', $player->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRecord !== null) {
                if ($existingRecord->request_hash !== $requestHash) {
                    throw new InvalidArgumentException('Idempotency key reused with different request payload.');
                }

                $decoded = json_decode((string) $existingRecord->response_payload, true, 512, JSON_THROW_ON_ERROR);
                $decoded['idempotent_replay'] = true;

                return $decoded;
            }

            /** @var City $lockedCity */
            $lockedCity = City::query()
                ->whereKey($city->id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromTime = CarbonImmutable::instance($lockedCity->economy_calculated_at)->utc();
            $checkpoint = $this->checkpointLockedCityFog(
                $player,
                $lockedCity,
                round((float) $baseFogPerHour, 6),
                $idempotencyKey,
                $targetTime
            );

            $fogFormatted = $checkpoint['fog_earned'];

            $responsePayload = [
                'idempotent_replay' => false,
                'fog_earned' => $fogFormatted,
                'storage_cap' => $checkpoint['storage_cap'],
                'economy_calculated_at' => $checkpoint['economy_calculated_at'],
                'segments' => $checkpoint['segments'],
            ];

            DB::table('transaction_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'economy.fog.synced',
                'payload' => json_encode([
                    'player_id' => $player->id,
                    'city_id' => $lockedCity->id,
                    'fog_earned' => $fogFormatted,
                    'storage_cap' => $checkpoint['storage_cap'],
                    'from' => $fromTime->toIso8601String(),
                    'to' => $targetTime->toIso8601String(),
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $targetTime,
                'published_at' => null,
                'attempts' => 0,
                'created_at' => $targetTime,
                'updated_at' => $targetTime,
            ]);

            DB::table('idempotency_records')->insert([
                'player_id' => $player->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'operation_type' => 'sync_fog_production',
                'response_status' => 200,
                'response_payload' => json_encode($responsePayload, JSON_THROW_ON_ERROR),
                'completed_at' => $targetTime,
                'created_at' => $targetTime,
                'updated_at' => $targetTime,
            ]);

            return $responsePayload;
        });
    }
}



