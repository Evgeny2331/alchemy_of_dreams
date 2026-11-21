<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CityIslandUnlockApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_island_unlock_and_idempotent_repeat(): void
    {
        $player = Player::create(['telegram_id' => 6301, 'first_name' => 'Navigator']);
        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Archipelago',
            'economy_calculated_at' => $t0,
        ]);

        $island0 = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        $island1 = Island::create([
            'city_id' => $city->id,
            'island_index' => 1,
            'is_unlocked' => false,
        ]);

        $island2 = Island::create([
            'city_id' => $city->id,
            'island_index' => 2,
            'is_unlocked' => false,
        ]);

        // Нельзя открыть остров 2, пока закрыт остров 1
        $this->withToken($plainToken)
            ->postJson("/api/v1/city/islands/{$island2->id}/unlock")
            ->assertStatus(422);

        // Открываем остров 1
        $unlock1 = $this->withToken($plainToken)
            ->postJson("/api/v1/city/islands/{$island1->id}/unlock");
        $unlock1->assertStatus(200)
            ->assertJsonPath('data.island.is_unlocked', true)
            ->assertJsonPath('data.already_unlocked', false);

        // Повторный вызов по уже открытому острову 1 идемпотентен
        $unlock1Repeat = $this->withToken($plainToken)
            ->postJson("/api/v1/city/islands/{$island1->id}/unlock");
        $unlock1Repeat->assertStatus(200)
            ->assertJsonPath('data.island.is_unlocked', true)
            ->assertJsonPath('data.already_unlocked', true);

        // Теперь открывается остров 2
        $unlock2 = $this->withToken($plainToken)
            ->postJson("/api/v1/city/islands/{$island2->id}/unlock");
        $unlock2->assertStatus(200)
            ->assertJsonPath('data.island.is_unlocked', true)
            ->assertJsonPath('data.already_unlocked', false);

        $this->assertTrue($island0->fresh()->is_unlocked);
    }
}
