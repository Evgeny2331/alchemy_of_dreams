<?php

namespace App\Modules\Residents\Services;

use App\Modules\Residents\Models\Resident;
use App\Modules\City\Models\Building;
use Illuminate\Support\Facades\DB;

class ResidentsService
{
    /**
     * Создать нового жителя в городе игрока.
     */
    public function createResident(int $cityId, string $archetype, string $name): Resident
    {
        if (!in_array($archetype, ['dream', 'phobia', 'memory'])) {
            abort(422, 'UNSUPPORTED_OPERATION');
        }

        return Resident::create([
            'city_id' => $cityId,
            'archetype' => $archetype,
            'name' => $name,
            'trust_level' => 0,
            'energy' => 10.00, // Стартовая энергия
            'status' => 'available',
        ]);
    }

    /**
     * Назначить жителя на здание.
     */
    public function assignToBuilding(int $playerId, int $residentId, int $buildingId): Resident
    {
        return DB::transaction(function () use ($playerId, $residentId, $buildingId) {
            $city = DB::table('cities')->where('player_id', $playerId)->lockForUpdate()->firstOrFail();

            $resident = Resident::where('id', $residentId)
                ->where('city_id', $city->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($resident->status === 'expedition') {
                abort(409, 'INVALID_GAME_STATE');
            }

            $building = Building::where('id', $buildingId)
                ->where('city_id', $city->id)
                ->firstOrFail();

            if ($building->status === 'demolished') {
                abort(409, 'INVALID_GAME_STATE');
            }

            $resident->assigned_building_id = $building->id;
            $resident->status = 'assigned';
            $resident->save();

            return $resident;
        });
    }
}
