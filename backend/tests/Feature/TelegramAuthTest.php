<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TelegramAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_player_telegram_auth_initializes_city_resources_island_and_session(): void
    {
        $userJson = json_encode([
            'id' => 99001122,
            'username' => 'dream_weaver',
            'first_name' => 'Алиса',
        ], JSON_THROW_ON_ERROR);

        $initData = http_build_query([
            'auth_date' => time(),
            'user' => $userJson,
            'hash' => 'dev_signature_hash',
        ]);

        $firstResponse = $this->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
        ]);

        $firstResponse->assertStatus(200)
            ->assertJsonPath('data.is_new_player', true)
            ->assertJsonPath('data.player.first_name', 'Алиса')
            ->assertJsonStructure([
                'data' => ['session_token', 'expires_at', 'player' => ['id', 'first_name'], 'is_new_player'],
                'meta' => ['request_id'],
            ]);

        $player = Player::where('telegram_id', 99001122)->firstOrFail();
        $this->assertNotNull($player->city);
        $this->assertEquals('Город Снов', $player->city->name);
        $this->assertEquals(1, $player->city->islands()->where('island_index', 0)->where('is_unlocked', true)->count());
        $this->assertEquals(5, DB::table('player_resources')->where('player_id', $player->id)->count());
        $this->assertEquals(1, PlayerSession::where('player_id', $player->id)->count());

        // Повторный вход того же игрока не создаёт дубль игрока/города, но открывает новую сессию
        $secondResponse = $this->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
        ]);

        $secondResponse->assertStatus(200)
            ->assertJsonPath('data.is_new_player', false);

        $this->assertEquals(1, Player::where('telegram_id', 99001122)->count());
        $this->assertEquals(2, PlayerSession::where('player_id', $player->id)->count());
    }
}
