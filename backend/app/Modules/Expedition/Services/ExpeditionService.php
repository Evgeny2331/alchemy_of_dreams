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

    private function getSpec(string $type): array
    {
        $specs = config('game_balance.expeditions', []);
        if (!is_array($specs) || !isset($specs[$type]) || !is_array($specs[$type])) {
            throw new InvalidArgumentException("Unsupported expedition type: {$type}");
        }
        return $specs[$type];
    }

    public function startExpedition(
        City $city,
        string $type,
        array $residentIds,
        ?string $balanceVersion = null,
        ?CarbonImmutable $now = null,
        ?string $idempotencyKey = null
    ): Expedition {
        $spec = $this->getSpec($type);
        $economy = $this->economy();

        if ($residentIds === []) {
            throw new InvalidArgumentException('Expedition requires at least one resident.');
        }

        $uniqueIds = array_values(array_unique($residentIds));
        if (count($uniqueIds) !== count($residentIds)) {
            throw new InvalidArgumentException('Duplicate resident IDs in expedition roster.');
        }

        $energyCostFormatted = $economy->normalizeDecimal((string) ($spec['energy_per_resident'] ?? '1.00'), 2);
        $energyCostScaled = $this->toScaledInt($energyCostFormatted, 2);
        $durationSeconds = (int) ($spec['duration_seconds'] ?? 300);
        $startedAt = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $endsAt = $startedAt->addSeconds($durationSeconds);
        $version = $balanceVersion ?? (string) config('game_balance.default_balance_version', '1.0');
        $opKey = $idempotencyKey ?? ("expedition_start:{$city->id}:{$type}:" . $startedAt->timestamp);

        $costs = [];
        foreach (($spec['cost'] ?? []) as $resourceType => $amount) {
            $economy->assertValidResourceType((string) $resourceType);
            $normalizedCost = $economy->normalizeDecimal((string) $amount, 6);
            if ($this->compareScaled($normalizedCost, '0.000000', 6) > 0) {
                $costs[(string) $resourceType] = $normalizedCost;
            }
        }

        return DB::transaction(function () use (
            $city,
            $type,
            $uniqueIds,
            $energyCostFormatted,
            $energyCostScaled,
            $costs,
            $startedAt,
            $endsAt,
            $version,
            $opKey,
            $economy
        ): Expedition {
            /** @var City $lockedCity */
            $lockedCity = City::query()->whereKey($city->id)->lockForUpdate()->firstOrFail();

            /** @var Player $player */
            $player = Player::query()->whereKey($lockedCity->player_id)->lockForUpdate()->firstOrFail();

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

                if ((int) $resident->city_id !== (int) $lockedCity->id) {
                    throw new InvalidArgumentException("Resident {$id} does not belong to city {$lockedCity->id}.");
                }

                if ($resident->status !== 'available') {
                    throw new RuntimeException("Resident {$id} is not available (status: {$resident->status}).");
                }

                $currentEnergyFormatted = $economy->normalizeDecimal((string) $resident->energy, 2);
                $currentEnergyScaled = $this->toScaledInt($currentEnergyFormatted, 2);
                if ($currentEnergyScaled < $energyCostScaled) {
                    throw new RuntimeException("Resident {$id} has insufficient energy.");
                }
            }

            foreach ($costs as $resourceType => $amount) {
                $economy->applyResourceDelta(
                    $player,
                    $resourceType,
                    '-' . $amount,
                    'expedition_start',
                    "{$opKey}:cost:{$resourceType}",
                    'city',
                    $lockedCity->id,
                    $startedAt
                );
            }

            $expedition = Expedition::create([
                'city_id' => $lockedCity->id,
                'expedition_type' => $type,
                'status' => 'active',
                'started_at' => $startedAt,
                'ends_at' => $endsAt,
                'completed_at' => null,
                'claimed_at' => null,
                'reward_payload' => null,
                'outcome_seed' => random_int(1, PHP_INT_MAX),
                'balance_version' => $version,
            ]);

            foreach ($uniqueIds as $id) {
                /** @var Resident $resident */
                $resident = $residents->get($id);
                $currentEnergyScaled = $this->toScaledInt(
                    $economy->normalizeDecimal((string) $resident->energy, 2),
                    2
                );
                $remainingScaled = max(0, $currentEnergyScaled - $energyCostScaled);

                $resident->update([
                    'energy' => $this->fromScaledInt($remainingScaled, 2),
                    'status' => 'expedition',
                    'assigned_building_id' => null,
                ]);

                ExpeditionResident::create([
                    'expedition_id' => $expedition->id,
                    'resident_id' => $resident->id,
                    'energy_spent' => $energyCostFormatted,
                ]);
            }

            return $expedition->fresh(['participants']);
        });
    }

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

    public function claimReward(Expedition $expedition, ?CarbonImmutable $now = null): array
    {
        $currentTime = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $economy = $this->economy();

        return DB::transaction(function () use ($expedition, $currentTime, $economy): array {
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
            $memoriesFormatted = $economy->normalizeDecimal((string) ($payload['memories'] ?? '0.000000'), 6);

            /** @var City $city */
            $city = City::query()->whereKey($locked->city_id)->firstOrFail();
            /** @var Player $player */
            $player = Player::query()->whereKey($city->player_id)->firstOrFail();

            if ($this->compareScaled($memoriesFormatted, '0.000000', 6) > 0) {
                $economy->applyResourceDelta(
                    $player,
                    'memories',
                    $memoriesFormatted,
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

    private function computeRewardPayload(Expedition $expedition): array
    {
        $spec = $this->getSpec($expedition->expedition_type);
        $min = (int) ($spec['memories_min'] ?? 5);
        $max = (int) ($spec['memories_max'] ?? 10);
        $seed = (string) ($expedition->outcome_seed ?? $expedition->id);
        $hash = hash('sha256', $expedition->id . ':' . $seed);
        $span = max(1, $max - $min + 1);
        $offset = hexdec(substr($hash, 0, 8)) % $span;
        $memories = $min + $offset;

        return [
            'memories' => $this->economy()->normalizeDecimal((string) $memories, 6),
        ];
    }

    private function compareScaled(string $a, string $b, int $scale): int
    {
        return $this->toScaledInt($a, $scale) <=> $this->toScaledInt($b, $scale);
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
