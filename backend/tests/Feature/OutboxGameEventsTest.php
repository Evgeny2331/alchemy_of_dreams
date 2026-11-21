<?php

namespace Tests\Feature;

use App\Modules\Alchemy\Models\Recipe;
use App\Modules\Alchemy\Models\RecipeComponent;
use App\Modules\Alchemy\Services\AlchemyService;
use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\City\Services\CityService;
use App\Modules\Economy\Services\OutboxService;
use App\Modules\Expedition\Services\ExpeditionService;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OutboxGameEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_processes_city_expedition_and_alchemy_events(): void
    {
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $player = Player::create(['telegram_id' => 6501, 'first_name' => 'OutboxTester']);

        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '500.000000',
            'created_at' => $t0,
            'updated_at' => $t0,
        ]);

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Outbox Citadel',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        BuildingCatalog::create([
            'building_key' => 'dream_spire',
            'category' => 'functional',
            'display_name' => 'Шпиль Грёз',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        $cityService = new CityService();
        $built = $cityService->buildBuilding($city, $island->id, 'dream_spire', 0, 0, $t0);
        $cityService->upgradeBuilding($built['building'], $t0->addMinutes(5));

        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Странник',
            'trust_level' => 1,
            'energy' => '5.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $expeditionService = new ExpeditionService();
        $expedition = $expeditionService->startExpedition($city, 'surface', [$resident->id], '1.0', $t0->addMinutes(10));
        $expeditionService->claimReward($expedition, $t0->addMinutes(20));

        $recipe = Recipe::create([
            'recipe_key' => 'moon_dew',
            'category' => 'functional',
            'result_type' => 'artifact',
            'result_key' => 'moon_dew_key',
            'metadata' => [],
            'is_active' => true,
        ]);
        RecipeComponent::create([
            'recipe_id' => $recipe->id,
            'component_type' => 'resource',
            'component_key' => 'fog',
            'quantity' => '10.000000',
        ]);

        $alchemyService = new AlchemyService();
        $alchemyService->transmute($player, [
            ['component_type' => 'resource', 'component_key' => 'fog', 'quantity' => '10'],
        ], $t0->addMinutes(25));

        $collectedTypes = [];
        $outboxService = new OutboxService();
        $summary = $outboxService->processBatch(function (object $event) use (&$collectedTypes): void {
            $collectedTypes[] = $event->event_type;
        }, ['limit' => 20], $t0->addMinutes(30));

        $this->assertSame(4, $summary['processed']);
        $this->assertSame(0, $summary['failed']);
        $this->assertSame([
            'city.building.built',
            'city.building.upgraded',
            'expedition.reward.claimed',
            'alchemy.recipe.transmuted',
        ], $collectedTypes);

        $unpublishedCount = DB::table('transaction_outbox')
            ->whereNull('published_at')
            ->count();
        $this->assertSame(0, $unpublishedCount);
    }
}
