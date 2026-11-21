<?php

namespace App\Modules\City\Services;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\Building;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Economy\Services\EconomyService;
use App\Modules\Economy\Services\OfflineEconomyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class CityService
{
    public function __construct(
        private readonly ?EconomyService $economyService = null,
        private readonly ?OfflineEconomyService $offlineEconomyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    private function offlineEconomy(): OfflineEconomyService
    {
        return $this->offlineEconomyService ?? app(OfflineEconomyService::class);
    }

    /**
     * Рассчитывает стоимость строительства здания с учетом исторического количества N.
     * Формула: Cost(N) = BaseCost * (growth_rate ^ (N - 1))
     */
    public function calculateBuildingCost(City $city, string $buildingKey): array
    {
        $historyCount = Building::where('city_id', $city->id)
            ->where('building_type', $buildingKey)
            ->count();

        $n = $historyCount + 1;

        $baseCost = 100.00;
        $growthRate = 1.15;

        $calculatedCost = ceil($baseCost * pow($growthRate, $n - 1));

        return [
            'building_key' => $buildingKey,
            'historical_count_n' => $n,
            'cost' => [
                'fog' => number_format($calculatedCost, 6, '.', ''),
            ],
        ];
    }

    /**
     * Проверка пересечения прямоугольников (footprint_width x footprint_height) на сетке острова.
     */
    public function isPositionAvailable(
        int $islandId,
        int $x,
        int $y,
        int $width,
        int $height,
        ?int $ignoreBuildingId = null
    ): bool {
        if ($x < 0 || $y < 0) {
            return false;
        }

        $newWidth = max(1, $width);
        $newHeight = max(1, $height);
        $newRight = $x + $newWidth;
        $newBottom = $y + $newHeight;

        $query = Building::query()
            ->leftJoin('building_catalog', 'buildings.building_catalog_id', '=', 'building_catalog.id')
            ->where('buildings.island_id', $islandId)
            ->where('buildings.status', '!=', 'demolished');

        if ($ignoreBuildingId !== null) {
            $query->where('buildings.id', '!=', $ignoreBuildingId);
        }

        $existingBuildings = $query->get([
            'buildings.position_x',
            'buildings.position_y',
            'building_catalog.footprint_width',
            'building_catalog.footprint_height',
        ]);

        foreach ($existingBuildings as $existing) {
            $exX = (int) $existing->position_x;
            $exY = (int) $existing->position_y;
            $exW = max(1, (int) ($existing->footprint_width ?? 1));
            $exH = max(1, (int) ($existing->footprint_height ?? 1));

            $exRight = $exX + $exW;
            $exBottom = $exY + $exH;

            $overlapsX = ($x < $exRight) && ($newRight > $exX);
            $overlapsY = ($y < $exBottom) && ($newBottom > $exY);

            if ($overlapsX && $overlapsY) {
                return false;
            }
        }

        return true;
    }

    /**
     * Предварительный расчет стоимости и валидности размещения без изменения состояния.
     */
    public function previewBuilding(City $city, int $islandId, string $buildingKey, int $positionX, int $positionY): array
    {
        /** @var BuildingCatalog|null $catalog */
        $catalog = BuildingCatalog::query()
            ->where('building_key', $buildingKey)
            ->where('is_active', true)
            ->first();

        if ($catalog === null) {
            throw new InvalidArgumentException("Unknown or inactive building_key: {$buildingKey}");
        }

        /** @var Island|null $island */
        $island = Island::query()
            ->whereKey($islandId)
            ->where('city_id', $city->id)
            ->first();

        if ($island === null) {
            throw new InvalidArgumentException("Island {$islandId} does not belong to city {$city->id}.");
        }

        $costInfo = $this->calculateBuildingCost($city, $buildingKey);
        $positionValid = (bool) $island->is_unlocked
            && $this->isPositionAvailable($island->id, $positionX, $positionY, (int) $catalog->footprint_width, (int) $catalog->footprint_height);

        $uniqueConflict = false;
        if ($catalog->is_unique) {
            $uniqueConflict = Building::query()
                ->where('city_id', $city->id)
                ->where('building_type', $buildingKey)
                ->where('status', '!=', 'demolished')
                ->exists();
        }

        return [
            'can_build' => $positionValid && !$uniqueConflict,
            'position_valid' => $positionValid,
            'next_building_number' => $costInfo['historical_count_n'],
            'cost' => $costInfo['cost'],
            'balance_version' => '1.0',
        ];
    }

    /**
     * Разблокирует остров города при условии, что предыдущий остров (island_index - 1) уже открыт.
     *
     * @return array{island: Island, already_unlocked: bool}
     */
    public function unlockIsland(Island $island): array
    {
        return DB::transaction(function () use ($island): array {
            /** @var Island $locked */
            $locked = Island::query()
                ->whereKey($island->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->is_unlocked) {
                return [
                    'island' => $locked->fresh(),
                    'already_unlocked' => true,
                ];
            }

            $index = (int) $locked->island_index;
            if ($index > 0) {
                $previousUnlocked = Island::query()
                    ->where('city_id', $locked->city_id)
                    ->where('island_index', $index - 1)
                    ->where('is_unlocked', true)
                    ->exists();

                if (!$previousUnlocked) {
                    throw new RuntimeException("Previous island with index " . ($index - 1) . " must be unlocked first.");
                }
            }

            $locked->update([
                'is_unlocked' => true,
            ]);

            return [
                'island' => $locked->fresh(),
                'already_unlocked' => false,
            ];
        });
    }

    /**
     * Строит здание на разблокированном острове города и списывает стоимость через EconomyService.
     *
     * @return array{building: Building, cost_info: array<string, mixed>}
     */
    public function buildBuilding(
        City $city,
        int $islandId,
        string $buildingKey,
        int $positionX,
        int $positionY,
        ?CarbonImmutable $now = null
    ): array {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();

        /** @var BuildingCatalog|null $catalog */
        $catalog = BuildingCatalog::query()
            ->where('building_key', $buildingKey)
            ->where('is_active', true)
            ->first();

        if ($catalog === null) {
            throw new InvalidArgumentException("Unknown or inactive building_key: {$buildingKey}");
        }

        return DB::transaction(function () use ($city, $islandId, $catalog, $buildingKey, $positionX, $positionY, $timestamp): array {
            /** @var City $lockedCity */
            $lockedCity = City::query()->whereKey($city->id)->lockForUpdate()->firstOrFail();

            /** @var Island|null $island */
            $island = Island::query()
                ->whereKey($islandId)
                ->where('city_id', $lockedCity->id)
                ->lockForUpdate()
                ->first();

            if ($island === null) {
                throw new InvalidArgumentException("Island {$islandId} does not belong to city {$lockedCity->id}.");
            }

            if (!$island->is_unlocked) {
                throw new RuntimeException("Island {$islandId} is not unlocked.");
            }

            if ($catalog->is_unique) {
                $alreadyBuilt = Building::query()
                    ->where('city_id', $lockedCity->id)
                    ->where('building_type', $buildingKey)
                    ->where('status', '!=', 'demolished')
                    ->exists();

                if ($alreadyBuilt) {
                    throw new RuntimeException("Unique building {$buildingKey} already exists in city {$lockedCity->id}.");
                }
            }

            if (!$this->isPositionAvailable($island->id, $positionX, $positionY, (int) $catalog->footprint_width, (int) $catalog->footprint_height)) {
                throw new RuntimeException("Position ({$positionX}, {$positionY}) on island {$island->id} is occupied.");
            }

            $costInfo = $this->calculateBuildingCost($lockedCity, $buildingKey);
            $costFog = (float) $costInfo['cost']['fog'];

            /** @var Player $player */
            $player = Player::query()->whereKey($lockedCity->player_id)->firstOrFail();

            $this->offlineEconomy()->checkpointLockedCityFog(
                $player,
                $lockedCity,
                null,
                "checkpoint:build:$lockedCity->id:$buildingKey:$positionX:$positionY:$timestamp->timestamp",
                $timestamp
            );

            $this->economy()->applyResourceDelta(
                $player,
                'fog',
                number_format(-$costFog, 6, '.', ''),
                'building_construct',
                "build:{$lockedCity->id}:{$buildingKey}:{$positionX}:{$positionY}:{$timestamp->timestamp}",
                'city',
                $lockedCity->id,
                $timestamp
            );

            $building = Building::create([
                'city_id' => $lockedCity->id,
                'island_id' => $island->id,
                'building_catalog_id' => $catalog->id,
                'building_type' => $buildingKey,
                'level' => 1,
                'position_x' => $positionX,
                'position_y' => $positionY,
                'status' => 'active',
                'built_at' => $timestamp,
                'demolished_at' => null,
            ]);

            DB::table('transaction_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'city.building.built',
                'payload' => json_encode([
                    'player_id' => $lockedCity->player_id,
                    'city_id' => $lockedCity->id,
                    'building_id' => $building->id,
                    'building_key' => $buildingKey,
                    'cost_fog' => $costInfo['cost']['fog'],
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $timestamp,
                'published_at' => null,
                'attempts' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return [
                'building' => $building,
                'cost_info' => $costInfo,
            ];
        });
    }

    /**
     * Перемещает активное здание на доступную позицию без изменения уровня и без увеличения N.
     */
    public function moveBuilding(Building $building, int $targetIslandId, int $targetX, int $targetY): Building
    {
        return DB::transaction(function () use ($building, $targetIslandId, $targetX, $targetY): Building {
            /** @var Building $locked */
            $locked = Building::query()
                ->whereKey($building->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'active') {
                throw new RuntimeException("Only active buildings can be moved (current status: {$locked->status}).");
            }

            /** @var Island|null $island */
            $island = Island::query()
                ->whereKey($targetIslandId)
                ->where('city_id', $locked->city_id)
                ->lockForUpdate()
                ->first();

            if ($island === null) {
                throw new InvalidArgumentException("Island {$targetIslandId} does not belong to city {$locked->city_id}.");
            }

            if (!$island->is_unlocked) {
                throw new RuntimeException("Island {$targetIslandId} is not unlocked.");
            }

            /** @var BuildingCatalog|null $catalog */
            $catalog = BuildingCatalog::query()
                ->whereKey($locked->building_catalog_id)
                ->first();

            $width = (int) ($catalog->footprint_width ?? 1);
            $height = (int) ($catalog->footprint_height ?? 1);

            if (!$this->isPositionAvailable($island->id, $targetX, $targetY, $width, $height, $locked->id)) {
                throw new RuntimeException("Target position ({$targetX}, {$targetY}) on island {$island->id} is occupied.");
            }

            $locked->update([
                'island_id' => $island->id,
                'position_x' => $targetX,
                'position_y' => $targetY,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Повышает уровень активного здания и списывает стоимость через EconomyService.
     *
     * @return array{building: Building, upgrade_cost: array<string, mixed>}
     */
    public function upgradeBuilding(Building $building, ?CarbonImmutable $now = null): array
    {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($building, $timestamp): array {
            /** @var Building $locked */
            $locked = Building::query()
                ->whereKey($building->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== 'active') {
                throw new RuntimeException("Only active buildings can be upgraded (current status: {$locked->status}).");
            }

            /** @var BuildingCatalog|null $catalog */
            $catalog = BuildingCatalog::query()
                ->whereKey($locked->building_catalog_id)
                ->first();

            if ($catalog !== null && !$catalog->supports_upgrade) {
                throw new RuntimeException("Building {$locked->building_type} does not support upgrades.");
            }

            /** @var City $city */
            $city = City::query()->whereKey($locked->city_id)->lockForUpdate()->firstOrFail();

            $currentLevel = (int) $locked->level;
            if ($currentLevel >= 3) {
                throw new RuntimeException("Building {$locked->id} has already reached maximum level 3.");
            }
            $nextLevel = $currentLevel + 1;
            $upgradeCostFog = ceil(75.00 * pow(1.25, $currentLevel));
            $upgradeCostFormatted = number_format($upgradeCostFog, 6, '.', '');

            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            $this->offlineEconomy()->checkpointLockedCityFog(
                $player,
                $city,
                null,
                "checkpoint:upgrade:$locked->id:to:$nextLevel:$timestamp->timestamp",
                $timestamp
            );

            $this->economy()->applyResourceDelta(
                $player,
                'fog',
                number_format(-$upgradeCostFog, 6, '.', ''),
                'building_upgrade',
                "upgrade:{$locked->id}:to:{$nextLevel}:{$timestamp->timestamp}",
                'building',
                $locked->id,
                $timestamp
            );

            $locked->update([
                'level' => $nextLevel,
            ]);

            DB::table('transaction_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'city.building.upgraded',
                'payload' => json_encode([
                    'player_id' => $city->player_id,
                    'city_id' => $city->id,
                    'building_id' => $locked->id,
                    'level' => $nextLevel,
                    'cost_fog' => $upgradeCostFormatted,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $timestamp,
                'published_at' => null,
                'attempts' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return [
                'building' => $locked->fresh(),
                'upgrade_cost' => [
                    'target_level' => $nextLevel,
                    'fog' => $upgradeCostFormatted,
                ],
            ];
        });
    }

    /**
     * Переводит здание в статус demolished (сохраняя историческую запись для формулы N).
     */
    public function demolishBuilding(Building $building, ?CarbonImmutable $now = null): Building
    {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($building, $timestamp): Building {
            /** @var Building $locked */
            $locked = Building::query()
                ->whereKey($building->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'demolished') {
                throw new RuntimeException("Building {$locked->id} is already demolished.");
            }

            /** @var City $city */
            $city = City::query()->whereKey($locked->city_id)->lockForUpdate()->firstOrFail();
            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            $this->offlineEconomy()->checkpointLockedCityFog(
                $player,
                $city,
                null,
                "checkpoint:demolish:$locked->id:$timestamp->timestamp",
                $timestamp
            );

            $locked->update([
                'status' => 'demolished',
                'demolished_at' => $timestamp,
            ]);

            return $locked->fresh();
        });
    }
}



