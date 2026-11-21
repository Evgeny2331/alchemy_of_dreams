<?php

namespace App\Modules\Residents\Services;

use App\Modules\City\Models\Building;
use App\Modules\City\Models\City;
use App\Modules\Residents\Models\Resident;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ResidentService
{
    public const VALID_ARCHETYPES = ['dream', 'phobia', 'memory'];
    public const MAX_TRUST_LEVEL = 10;

    public function createResident(
        City $city,
        string $archetype,
        string $name,
        string|float $energy = '10.00',
        int $trustLevel = 0
    ): Resident {
        if (!in_array($archetype, self::VALID_ARCHETYPES, true)) {
            throw new InvalidArgumentException("Invalid resident archetype: {$archetype}.");
        }

        $energyFloat = (float) $energy;
        if ($energyFloat < 0.0) {
            throw new InvalidArgumentException('Resident energy cannot be negative.');
        }

        if ($trustLevel < 0) {
            throw new InvalidArgumentException('Resident trust level cannot be negative.');
        }

        return Resident::create([
            'city_id' => $city->id,
            'archetype' => $archetype,
            'name' => $name,
            'trust_level' => $trustLevel,
            'energy' => number_format($energyFloat, 2, '.', ''),
            'status' => 'available',
            'assigned_building_id' => null,
        ]);
    }

    public function assignToBuilding(Resident $resident, Building $building): Resident
    {
        if ((int) $resident->city_id !== (int) $building->city_id) {
            throw new InvalidArgumentException("Resident {$resident->id} and building {$building->id} belong to different cities.");
        }

        return DB::transaction(function () use ($resident, $building): Resident {
            /** @var Resident $lockedResident */
            $lockedResident = Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Building $lockedBuilding */
            $lockedBuilding = Building::query()
                ->whereKey($building->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedResident->city_id !== (int) $lockedBuilding->city_id) {
                throw new InvalidArgumentException("Resident {$lockedResident->id} and building {$lockedBuilding->id} belong to different cities.");
            }

            if ($lockedBuilding->status !== 'active') {
                throw new RuntimeException("Cannot assign resident to non-active building {$lockedBuilding->id}.");
            }

            if (in_array($lockedResident->status, ['expedition', 'resting'], true)) {
                throw new RuntimeException("Cannot assign resident {$lockedResident->id} while in status {$lockedResident->status}.");
            }

            if ((float) $lockedResident->energy <= 0.0) {
                throw new RuntimeException("Resident {$lockedResident->id} has no energy.");
            }

            $lockedResident->update([
                'assigned_building_id' => $lockedBuilding->id,
                'status' => 'assigned',
            ]);

            return $lockedResident->fresh();
        });
    }

    public function unassignFromBuilding(Resident $resident): Resident
    {
        return DB::transaction(function () use ($resident): Resident {
            /** @var Resident $lockedResident */
            $lockedResident = Resident::query()
                ->whereKey($resident->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedResident->status === 'expedition') {
                throw new RuntimeException("Cannot unassign resident {$lockedResident->id} while on expedition.");
            }

            $lockedResident->update([
                'assigned_building_id' => null,
                'status' => 'available',
            ]);

            return $lockedResident->fresh();
        });
    }

    public function unassign(Resident $resident): Resident
    {
        return $this->unassignFromBuilding($resident);
    }

    /**
     * Вычисляет бонус жителя на стороне сервера с учетом доверия и максимального доверия.
     *
     * @return array{multiplier_bonus: string, permanent_max_trust_bonus: string, total_bonus_ratio: string}
     */
    public function calculateResidentBonus(Resident $resident): array
    {
        $trust = max(0, (int) $resident->trust_level);
        $perTrustStep = match ($resident->archetype) {
            'dream' => 0.02,
            'memory' => 0.015,
            'phobia' => 0.015,
            default => 0.01,
        };

        $trustBonus = round($trust * $perTrustStep, 6);
        $permanentBonus = $trust >= self::MAX_TRUST_LEVEL ? 0.10 : 0.0;
        $total = round($trustBonus + $permanentBonus, 6);

        return [
            'multiplier_bonus' => number_format($trustBonus, 6, '.', ''),
            'permanent_max_trust_bonus' => number_format($permanentBonus, 6, '.', ''),
            'total_bonus_ratio' => number_format($total, 6, '.', ''),
        ];
    }
}
