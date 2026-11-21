<?php

namespace App\Modules\Expedition\Services;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\EconomyService;
use App\Modules\Expedition\Models\Expedition;
use App\Modules\Expedition\Models\ExpeditionResident;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class ExpeditionService
{
    public function __construct(
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    private const SPECS = [
        'surface' => [
            'duration_seconds' => 300,
            'energy_per_resident' => 1.0,
            'memories_min' => 5,
            'memories_max' => 10,
        ],
        'deep' => [
            'duration_seconds' => 2700,
            'energy_per_resident' => 3.0,
            'memories_min' => 20,
            'memories_max' => 35,
        ],
    ];

    /**
     * Запускает экспедицию для выбранных жителей города.
     *
     * @param  list<int>  $residentIds
     */
    public function startExpedition(
        City $city,
        string $type,
        array $residentIds,
        string $balanceVersion = '1.0',
        ?CarbonImmutable $now = null
    ): Expedition {
        if (!isset(self::SPECS[$type])) {
            throw new InvalidArgumentException("Unsupported expedition type: {$type}");
        }

        if ($residentIds === []) {
            throw new InvalidArgumentException('Expedition requires at least one resident.');
        }

        $uniqueIds = array_values(array_unique($residentIds));
        if (count($uniqueIds) !== count($residentIds)) {
            throw new InvalidArgumentException('Duplicate resident IDs in expedition roster.');
        }

        $spec = self::SPECS[$type];
        $energyCost = $spec['energy_per_resident'];
        $startedAt = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $endsAt = $startedAt->addSeconds($spec['duration_seconds']);

        return DB::transaction(function () use (
            $city,
            $type,
            $uniqueIds,
            $energyCost,
            $startedAt,
            $endsAt,
            $balanceVersion
        ): Expedition {
            City::query()->whereKey($city->id)->lockForUpdate()->firstOrFail();

            $residents = Resident::query()
                ->whereIn('id', $uniqueIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($residents->count() !== count($uniqueIds)) {
                throw new InvalidArgumentException('One or more residents were not found.');
            }

            foreach ($uniqueIds as $id) {
                /** @var Resident $resident */
                $resident = $residents->get($id);

                if ($resident->city_id !== $city->id) {
                    throw new InvalidArgumentException("Resident {$id} does not belong to city {$city->id}.");
                }

                if ($resident->status !== 'available') {
                    throw new RuntimeException("Resident {$id} is not available (status: {$resident->status}).");
                }

                if ((float) $resident->energy < $energyCost) {
                    throw new RuntimeException("Resident {$id} has insufficient energy.");
                }
            }

            $expedition = Expedition::create([
                'city_id' => $city->id,
                'expedition_type' => $type,
                'status' => 'active',
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'completed_at' => null,
                'claimed_at' => null,
                'reward_payload' => null,
                'outcome_seed' => random_int(1, PHP_INT_MAX),
                'balance_version' => $balanceVersion,
            ]);

            foreach ($uniqueIds as $id) {
                /** @var Resident $resident */
                $resident = $residents->get($id);
                $remainingEnergy = round((float) $resident->energy - $energyCost, 2);

                $resident->update([
                    'energy' => number_format($remainingEnergy, 2, '.', ''),
                    'status' => 'expedition',
                    'assigned_building_id' => null,
                ]);

                ExpeditionResident::create([
                    'expedition_id' => $expedition->id,
                    'resident_id' => $resident->id,
                    'energy_spent' => number_format($energyCost, 2, '.', ''),
                ]);
            }

            return $expedition->fresh(['participants']);
        });
    }

    /**
     * Завершает экспедицию (если ends_at <= now) и фиксирует награду.
     */
    public function completeExpedition(Expedition $expedition, ?CarbonImmutable $now = null): Expedition
    {
        $currentTime = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($expedition, $currentTime): Expedition {
            /** @var Expedition $locked */
            $locked = Expedition::query()
                ->whereKey($expedition->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'claimed' || $locked->status === 'cancelled') {
                return $locked->fresh(['participants']);
            }

            if ($locked->status === 'completed' && $locked->reward_payload !== null) {
                return $locked->fresh(['participants']);
            }

            if ($currentTime->lt(CarbonImmutable::instance($locked->ends_at))) {
                throw new RuntimeException('Expedition is not ready to complete yet.');
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => $currentTime,
                'reward_payload' => $this->computeRewardPayload($locked),
            ]);

            return $locked->fresh(['participants']);
        });
    }

    /**
     * Помечает награду экспедиции как полученную ровно один раз и начисляет memories через EconomyService.
     *
     * @return array<string, mixed>
     */
    public function claimReward(Expedition $expedition, ?CarbonImmutable $now = null): array
    {
        $currentTime = ($now ?? CarbonImmutable::now('UTC'))->utc();

        return DB::transaction(function () use ($expedition, $currentTime): array {
            /** @var Expedition $locked */
            $locked = Expedition::query()
                ->whereKey($expedition->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === 'claimed') {
                throw new RuntimeException('Expedition reward has already been claimed.');
            }

            if ($locked->status === 'cancelled') {
                throw new RuntimeException('Cancelled expedition cannot be claimed.');
            }

            if ($locked->status === 'active') {
                if ($currentTime->lt(CarbonImmutable::instance($locked->ends_at))) {
                    throw new RuntimeException('Expedition has not finished yet.');
                }

                $locked->update([
                    'status' => 'completed',
                    'completed_at' => $currentTime,
                    'reward_payload' => $this->computeRewardPayload($locked),
                ]);
            }

            $payload = $locked->reward_payload ?? ['memories' => '0.000000'];
            $memoriesReward = (float) ($payload['memories'] ?? 0.0);

            /** @var City $city */
            $city = City::query()->whereKey($locked->city_id)->firstOrFail();
            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            if ($memoriesReward > 0) {
                $this->economy()->applyResourceDelta(
                    $player,
                    'memories',
                    number_format($memoriesReward, 6, '.', ''),
                    'expedition_claim',
                    "expedition_claim:{$locked->id}",
                    'expedition',
                    $locked->id,
                    $currentTime
                );
            }

            $locked->update([
                'status' => 'claimed',
                'claimed_at' => $currentTime,
            ]);

            DB::table('transaction_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'expedition.reward.claimed',
                'payload' => json_encode([
                    'player_id' => $city->player_id,
                    'city_id' => $city->id,
                    'expedition_id' => $locked->id,
                    'rewards' => $payload,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $currentTime,
                'published_at' => null,
                'attempts' => 0,
                'created_at' => $currentTime,
                'updated_at' => $currentTime,
            ]);

            ExpeditionResident::query()
                ->where('expedition_id', $locked->id)
                ->pluck('resident_id')
                ->each(function ($residentId): void {
                    Resident::query()
                        ->whereKey($residentId)
                        ->where('status', 'expedition')
                        ->update(['status' => 'available']);
                });

            return [
                'expedition' => $locked->fresh(),
                'rewards' => $payload,
            ];
        });
    }

    /**
     * Детерминированно вычисляет полезную нагрузку награды на основе outcome_seed экспедиции.
     *
     * @return array{memories: string}
     */
    private function computeRewardPayload(Expedition $expedition): array
    {
        $spec = self::SPECS[$expedition->expedition_type] ?? self::SPECS['surface'];
        $seed = (string) ($expedition->outcome_seed ?? $expedition->id);
        $hash = hash('sha256', $expedition->id . ':' . $seed);
        $span = max(1, $spec['memories_max'] - $spec['memories_min'] + 1);
        $offset = hexdec(substr($hash, 0, 8)) % $span;
        $memories = $spec['memories_min'] + $offset;

        return [
            'memories' => number_format((float) $memories, 6, '.', ''),
        ];
    }
}

