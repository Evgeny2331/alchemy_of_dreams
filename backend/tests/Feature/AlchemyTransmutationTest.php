<?php

namespace Tests\Feature;

use App\Modules\Alchemy\Models\PlayerRecipeArchive;
use App\Modules\Alchemy\Models\Recipe;
use App\Modules\Alchemy\Models\RecipeComponent;
use App\Modules\Alchemy\Services\AlchemyService;
use App\Modules\Auth\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlchemyTransmutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipe_matching_order_independence_and_archive_idempotency(): void
    {
        $player = Player::create(['telegram_id' => 3001, 'first_name' => 'Alchemist']);

        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '100.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $recipe = Recipe::create([
            'recipe_key' => 'lucid_ink',
            'category' => 'functional',
            'result_type' => 'artifact',
            'result_key' => 'lucid_ink_vial',
            'metadata' => ['tier' => 1],
            'is_active' => true,
        ]);

        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'resource',
            'component_key' => 'fog',
            'quantity' => '15.000000',
        ]);

        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'essence',
            'component_key' => 'dream_dust',
            'quantity' => '2.000000',
        ]);

        $service = new AlchemyService();
        $unlockedAt = CarbonImmutable::parse('2026-11-21 02:00:00', 'UTC');

        // Передаем компоненты в обратном порядке
        $first = $service->transmute($player, [
            ['component_type' => 'essence', 'component_key' => 'dream_dust', 'quantity' => '2'],
            ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '15'],
        ], $unlockedAt);

        $this->assertTrue($first['matched']);
        $this->assertTrue($first['newly_unlocked']);
        $this->assertEquals('lucid_ink', $first['recipe']->recipe_key);
        $this->assertEquals(1, PlayerRecipeArchive::query()->where('player_id', $player->id)->count());

        // Повторный синтез того же рецепта не должен создавать дубль в архиве
        $second = $service->transmute($player, [
            ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '15'],
            ['component_type' => 'essence', 'component_key' => 'dream_dust', 'quantity' => '2'],
        ], $unlockedAt->addMinutes(10));

        $this->assertTrue($second['matched']);
        $this->assertFalse($second['newly_unlocked']);
        $this->assertEquals(1, PlayerRecipeArchive::query()->where('player_id', $player->id)->count());

        $fogAfterTwoTransmutes = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('70.000000', (string) $fogAfterTwoTransmutes);

        // Неизвестная комбинация возвращает matched = false без записи в архив
        $unknown = $service->transmute($player, [
            ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '1'],
        ], $unlockedAt->addMinutes(20));

        $this->assertFalse($unknown['matched']);
        $this->assertNull($unknown['recipe']);
        $this->assertEquals(1, PlayerRecipeArchive::query()->where('player_id', $player->id)->count());
    }
}
