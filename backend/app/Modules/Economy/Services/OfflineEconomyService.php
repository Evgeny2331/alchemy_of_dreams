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
    public const BASE_STORAGE_CAPACITY = '200.000000';

    public function __construct(
        private readonly WorldPhaseService $worldPhaseService,
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    public function calculateCityBaseFogPerHour(City $city): string
    {
        $economy = $this->economy();
        if (!Schema::hasTable('buildings') || !Schema::hasTable('building_level_configs')) {
            return '0.000000';
        }

        $rows = DB::table('buildings as b')
            ->join('building_level_configs as blc', function ($join): void {
                $join->on('blc.building_catalog_id', '=', 'b.building_catalog_id')
                    ->on('blc.level', '=', 'b.level');
            })
            ->where('b.city_id', $city->id)
            ->where('b.status', 'active')
            ->pluck('blc.fog_per_hour');

        $totalScaled = 0;
        foreach ($rows as $val) {
            $norm = $economy->normalizeDecimal((string) $val, 6);
            if (!str_starts_with($norm, '-')) {
                $totalScaled += $this->toScaledInt($norm, 6);
            }
        }

        return $this->fromScaledInt($totalScaled, 6);
    }

    public function calculateFogStorageCap(City $city): string
    {
        $economy = $this->economy();
        $baseScaled = $this->toScaledInt($economy->normalizeDecimal(self::BASE_STORAGE_CAPACITY, 6), 6);
        $extraScaled = 0;

        if (Schema::hasTable('buildings') && Schema::hasTable('building_level_configs')) {
            $rows = DB::table('buildings as b')
                ->join('building_level_configs as blc', function ($join): void {
                    $join->on('blc.building_catalog_id', '=', 'b.building_catalog_id')
                        ->on('blc.level', '=', 'b.level');
                })
                ->where('b.city_id', $city->id)
                ->where('b.status', 'active')
                ->pluck('blc.storage_capacity');

            foreach ($rows as $val) {
                $norm = $economy->normalizeDecimal((string) $val, 6);
                if (!str_starts_with($norm, '-')) {
                    $extraScaled += $this->toScaledInt($norm, 6);
                }
            }
        }

        return $this->fromScaledInt($baseScaled + $extraScaled, 6);
    }

    public function checkpointLockedCityFog(
        Player $player,
        City $lockedCity,
        string|int|null $overrideBaseFogPerHour,
        string $idempotencyKey,
        CarbonImmutable $targetTime
    ): array {
        $economy = $this->economy();
        $economy->assertValidResourceType('fog');

        $fromTime = CarbonImmutable::instance($lockedCity->economy_calculated_at)->utc();
        $effectiveTargetTime = $targetTime->lt($fromTime) ? $fromTime : $targetTime;

        $baseFogPerHour = $overrideBaseFogPerHour !== null
            ? $economy->normalizeDecimal((string) $overrideBaseFogPerHour, 6)
            : $this->calculateCityBaseFogPerHour($lockedCity);

        $calc = $this->worldPhaseService->calculateFogForInterval($fromTime, $effectiveTargetTime, $baseFogPerHour);
        $rawFogScaled = $this->toScaledInt($economy->normalizeDecimal((string) $calc['total_fog'], 6), 6);
        $storageCapFormatted = $this->calculateFogStorageCap($lockedCity);
        $storageCapScaled = $this->toScaledInt($storageCapFormatted, 6);

        $existingResource = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->lockForUpdate()
            ->first();

        $currentBalanceFormatted = $existingResource !== null
            ? $economy->normalizeDecimal((string) $existingResource->balance, 6)
            : '0.000000';
        $currentBalanceScaled = $this->toScaledInt($currentBalanceFormatted, 6);

        $availableCapacityScaled = max(0, $storageCapScaled - $currentBalanceScaled);
        $actualFogScaled = min($rawFogScaled, $availableCapacityScaled);
        $fogFormatted = $this->fromScaledInt($actualFogScaled, 6);

        if ($actualFogScaled > 0) {
            $newBalanceFormatted = $this->fromScaledInt($currentBalanceScaled + $actualFogScaled, 6);

            if ($existingResource !== null) {
                DB::table('player_resources')
                    ->where('id', $existingResource->id)
                    ->update([
                        'balance' => $newBalanceFormatted,
                        'updated_at' => $effectiveTargetTime,
                    ]);
            } else {
                DB::table('player_resources')->insert([
                    'player_id' => $player->id,
                    'resource_type' => 'fog',
                    'balance' => $newBalanceFormatted,
                    'created_at' => $effectiveTargetTime,
                    'updated_at' => $effectiveTargetTime,
                ]);
            }

            $ledgerPayload = [
                'player_id' => $player->id,
                'resource_type' => 'fog',
                'amount' => $fogFormatted,
                'operation_type' => 'offline_production',
                'idempotency_key' => $idempotencyKey,
                'created_at' => $effectiveTargetTime,
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
                $ledgerPayload['updated_at'] = $effectiveTargetTime;
            }

            DB::table('resource_ledgers')->insert($ledgerPayload);
        }

        $lockedCity->update([
            'economy_calculated_at' => $effectiveTargetTime,
        ]);

        return [
            'fog_earned' => $fogFormatted,
            'storage_cap' => $storageCapFormatted,
            'economy_calculated_at' => $effectiveTargetTime->toIso8601String(),
            'segments' => $calc['segments'],
        ];
    }

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

        $economy = $this->economy();
        $economy->assertValidResourceType('fog');

        $targetTime = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'))->utc();
        $normalizedRate = $economy->normalizeDecimal($baseFogPerHour, 6);

        $requestHash = hash('sha256', json_encode([
            'player_id' => $player->id,
            'city_id' => $city->id,
            'base_fog_per_hour' => $normalizedRate,
            'target_time' => $targetTime->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $player,
            $city,
            $normalizedRate,
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
                $normalizedRate,
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
