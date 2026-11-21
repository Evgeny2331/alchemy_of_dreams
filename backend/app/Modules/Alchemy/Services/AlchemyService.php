<?php

namespace App\Modules\Alchemy\Services;

use App\Modules\Alchemy\Models\PlayerRecipeArchive;
use App\Modules\Alchemy\Models\Recipe;
use App\Modules\Auth\Models\Player;
use App\Modules\Economy\Services\EconomyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AlchemyService
{
    public function __construct(
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    public function findMatchingRecipe(array $components): ?Recipe
    {
        if ($components === []) {
            throw new InvalidArgumentException('At least one component is required for alchemy.');
        }

        $inputSignature = $this->normalizeComponents($components);

        /** @var list<Recipe> $recipes */
        $recipes = Recipe::query()
            ->with('components')
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($recipes as $recipe) {
            $recipeSignature = $this->normalizeComponents(
                $recipe->components->map(fn ($c) => [
                    'component_type' => $c->component_type,
                    'component_key' => $c->component_key,
                    'quantity' => (string) $c->quantity,
                ])->all()
            );

            if ($recipeSignature === $inputSignature) {
                return $recipe;
            }
        }

        return null;
    }

    public function transmute(
        Player $player,
        array $components,
        ?CarbonImmutable $now = null,
        ?string $idempotencyKey = null
    ): array {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $economy = $this->economy();
        $normalized = $this->normalizeComponents($components);
        $recipe = $this->findMatchingRecipe($components);
        $opKey = $idempotencyKey ?? hash('sha256', json_encode([
            'player_id' => $player->id,
            'components' => $normalized,
            'ts' => $timestamp->toIso8601String(),
        ], JSON_THROW_ON_ERROR));

        if ($recipe === null) {
            return DB::transaction(function () use ($player, $normalized, $timestamp, $opKey, $economy): array {
                $protectedTypes = (array) config('game_balance.alchemy.protected_component_types', ['metaphor', 'critical_metaphor']);
                $failedFogCap = $economy->normalizeDecimal((string) config('game_balance.alchemy.failed_experiment_consumes_base_fog', '0.000000'), 6);

                foreach ($normalized as $item) {
                    if ($item['component_type'] !== 'resource') {
                        continue;
                    }
                    if (in_array($item['component_key'], $protectedTypes, true)) {
                        continue;
                    }

                    $resourceKey = $item['component_key'];
                    $requestedQty = $economy->normalizeDecimal((string) $item['quantity'], 6);
                    $economy->assertValidResourceType($resourceKey);

                    $currentBalance = $economy->normalizeDecimal(
                        (string) (DB::table('player_resources')
                            ->where('player_id', $player->id)
                            ->where('resource_type', $resourceKey)
                            ->lockForUpdate()
                            ->value('balance') ?? '0.000000'),
                        6
                    );

                    if ($this->toScaledInt($currentBalance, 6) < $this->toScaledInt($requestedQty, 6)) {
                        throw new RuntimeException("Insufficient {$resourceKey} balance (required: {$requestedQty}, available: {$currentBalance}).");
                    }

                    if ($resourceKey === 'fog' && $this->toScaledInt($failedFogCap, 6) > 0) {
                        $spend = $this->minDecimal($requestedQty, $failedFogCap);
                        if ($this->toScaledInt($spend, 6) > 0) {
                    $economy->applyResourceDelta(
                                $player,
                                'fog',
                                '-' . $spend,
                                'alchemy_failed_experiment',
                                "{$opKey}:fail:fog",
                                'player',
                                $player->id,
                                $timestamp
                            );
                        }
                    }
                }

                DB::table('transaction_outbox')->insert([
                    'event_id' => (string) Str::uuid(),
                    'event_type' => 'alchemy.experiment.unmatched',
                    'payload' => json_encode([
                        'player_id' => $player->id,
                        'components' => $normalized,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => $timestamp,
                    'published_at' => null,
                    'attempts' => 0,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                return [
                    'matched' => false,
                    'recipe' => null,
                    'archive_entry' => null,
                    'newly_unlocked' => false,
                ];
            });
        }

        return DB::transaction(function () use ($player, $recipe, $normalized, $timestamp, $opKey, $economy): array {
            $protectedTypes = (array) config('game_balance.alchemy.protected_component_types', ['metaphor', 'critical_metaphor']);
            $protectedCategories = (array) config('game_balance.alchemy.protected_categories', ['critical']);
            $isProtectedRecipe = in_array((string) $recipe->category, $protectedCategories, true);

            foreach ($normalized as $item) {
                if ($item['component_type'] !== 'resource') {
                    continue;
                }
                if ($isProtectedRecipe && in_array($item['component_key'], $protectedTypes, true)) {
                    continue;
                }

                $cost = $economy->normalizeDecimal((string) $item['quantity'], 6);
                $resourceKey = $item['component_key'];

                if ($this->toScaledInt($cost, 6) > 0) {
                    $economy->applyResourceDelta(
                        $player,
                        $resourceKey,
                        '-' . $cost,
                        'alchemy_transmute',
                        "{$opKey}:consume:{$recipe->id}:{$resourceKey}",
                        'recipe',
                        $recipe->id,
                        $timestamp
                    );
                }
            }

            $existing = PlayerRecipeArchive::query()
                ->where('player_id', $player->id)
                ->where('recipe_id', $recipe->id)
                ->first();

            $newlyUnlocked = false;

            if ($existing === null) {
                DB::table('player_recipe_archive')->insertOrIgnore([
                    'player_id' => $player->id,
                    'recipe_id' => $recipe->id,
                    'unlocked_at' => $timestamp,
                ]);

                $existing = PlayerRecipeArchive::query()
                    ->where('player_id', $player->id)
                    ->where('recipe_id', $recipe->id)
                    ->firstOrFail();

                $newlyUnlocked = true;
            }

            $resultPayload = is_array($recipe->result_payload) ? $recipe->result_payload : [];
            if (
                $newlyUnlocked
                && isset($resultPayload['resource_type'], $resultPayload['amount'])
                && is_string($resultPayload['resource_type'])
            ) {
                $rewardAmount = $economy->normalizeDecimal((string) $resultPayload['amount'], 6);
                if ($this->toScaledInt($rewardAmount, 6) > 0) {
                    $economy->applyResourceDelta(
                        $player,
                        $resultPayload['resource_type'],
                        $rewardAmount,
                        'alchemy_unlock_reward',
                        "{$opKey}:unlock_reward:{$recipe->id}",
                        'recipe',
                        $recipe->id,
                        $timestamp
                    );
                }
            }

            DB::table('transaction_outbox')->insert([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'alchemy.recipe.transmuted',
                'payload' => json_encode([
                    'player_id' => $player->id,
                    'recipe_id' => $recipe->id,
                    'recipe_key' => $recipe->recipe_key,
                    'newly_unlocked' => $newlyUnlocked,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $timestamp,
                'published_at' => null,
                'attempts' => 0,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return [
                'matched' => true,
                'recipe' => $recipe->fresh(['components']),
                'archive_entry' => $existing,
                'newly_unlocked' => $newlyUnlocked,
            ];
        });
    }

    private function normalizeComponents(array $items): array
    {
        $economy = $this->economy();
        $aggregated = [];

        foreach ($items as $item) {
            if (
                !isset($item['component_type'], $item['component_key'], $item['quantity'])
                || !is_string($item['component_type'])
                || !is_string($item['component_key'])
            ) {
                throw new InvalidArgumentException('Invalid component payload.');
            }

            $type = trim($item['component_type']);
            $key = trim($item['component_key']);
            $qtyNorm = $economy->normalizeDecimal((string) $item['quantity'], 6);
            $qtyScaled = $this->toScaledInt($qtyNorm, 6);

            if ($type === '' || $key === '' || $qtyScaled <= 0) {
                throw new InvalidArgumentException('Component type, key, and positive quantity are required.');
            }

            $mapKey = $type . "\0" . $key;
            $currentScaled = $aggregated[$mapKey]['scaled'] ?? 0;
            $aggregated[$mapKey] = [
                'component_type' => $type,
                'component_key' => $key,
                'scaled' => $currentScaled + $qtyScaled,
            ];
        }

        usort($aggregated, function (array $a, array $b): int {
            return [$a['component_type'], $a['component_key']]
                <=> [$b['component_type'], $b['component_key']];
        });

        return array_values(array_map(function (array $row): array {
            return [
                'component_type' => $row['component_type'],
                'component_key' => $row['component_key'],
                'quantity' => $this->fromScaledInt($row['scaled'], 6),
            ];
        }, $aggregated));
    }

    private function minDecimal(string $a, string $b): string
    {
        $sa = $this->toScaledInt($this->economy()->normalizeDecimal($a, 6), 6);
        $sb = $this->toScaledInt($this->economy()->normalizeDecimal($b, 6), 6);
        return $this->fromScaledInt(min($sa, $sb), 6);
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
