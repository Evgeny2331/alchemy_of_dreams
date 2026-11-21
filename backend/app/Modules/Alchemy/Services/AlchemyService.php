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

class AlchemyService
{
    public function __construct(
        private readonly ?EconomyService $economyService = null
    ) {}

    private function economy(): EconomyService
    {
        return $this->economyService ?? app(EconomyService::class);
    }

    /**
     * Подбирает активный рецепт по списку компонентов:
     * [['component_type' => '...', 'component_key' => '...', 'quantity' => '1.000000'], ...]
     *
     * @param  list<array{component_type: string, component_key: string, quantity: string|int|float}>  $components
     */
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

    /**
     * Проводит алхимический синтез для игрока, списывает ресурсные компоненты через EconomyService
     * и при совпадении рецепта фиксирует его в player_recipe_archive без создания дублей.
     *
     * @param  list<array{component_type: string, component_key: string, quantity: string|int|float}>  $components
     * @return array{matched: bool, recipe: ?Recipe, archive_entry: ?PlayerRecipeArchive, newly_unlocked: bool}
     */
    public function transmute(Player $player, array $components, ?CarbonImmutable $now = null): array
    {
        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $normalized = $this->normalizeComponents($components);
        $recipe = $this->findMatchingRecipe($components);

        if ($recipe === null) {
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
        }

        return DB::transaction(function () use ($player, $recipe, $normalized, $timestamp): array {
            foreach ($normalized as $item) {
                if ($item['component_type'] !== 'resource') {
                    continue;
                }

                $cost = (float) $item['quantity'];
                $resourceKey = $item['component_key'];

                $this->economy()->applyResourceDelta(
                    $player,
                    $resourceKey,
                    number_format(-$cost, 6, '.', ''),
                    'alchemy_transmute',
                    "alchemy:{$player->id}:{$recipe->id}:{$resourceKey}:" . (string) Str::uuid(),
                    'recipe',
                    $recipe->id,
                    $timestamp
                );
            }

            $resultPayload = is_array($recipe->result_payload) ? $recipe->result_payload : [];
            if (
                isset($resultPayload['resource_type'], $resultPayload['amount'])
                && is_string($resultPayload['resource_type'])
                && (float) $resultPayload['amount'] > 0
            ) {
                $this->economy()->applyResourceDelta(
                    $player,
                    $resultPayload['resource_type'],
                    number_format((float) $resultPayload['amount'], 6, '.', ''),
                    'alchemy_result',
                    "alchemy_result:{$player->id}:{$recipe->id}:" . (string) Str::uuid(),
                    'recipe',
                    $recipe->id,
                    $timestamp
                );
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

    /**
     * Нормализует и сортирует список компонентов для независимого от порядка сравнения.
     *
     * @param  list<array{component_type: string, component_key: string, quantity: string|int|float}>  $items
     * @return list<array{component_type: string, component_key: string, quantity: string}>
     */
    private function normalizeComponents(array $items): array
    {
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
            $qty = (float) $item['quantity'];

            if ($type === '' || $key === '' || $qty <= 0) {
                throw new InvalidArgumentException('Component type, key, and positive quantity are required.');
            }

            $mapKey = $type . "\0" . $key;
            $aggregated[$mapKey] = [
                'component_type' => $type,
                'component_key' => $key,
                'quantity' => ($aggregated[$mapKey]['quantity'] ?? 0.0) + $qty,
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
                'quantity' => number_format($row['quantity'], 6, '.', ''),
            ];
        }, $aggregated));
    }
}
