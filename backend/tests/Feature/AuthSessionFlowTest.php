<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuthSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_login_protected_route_and_logout_revocation(): void
    {
        $botToken = '123456789:TEST_BOT_TOKEN_FOR_ALCHEMY';
        Config::set('services.telegram.bot_token', $botToken);

        $userPayload = json_encode([
            'id' => 987654321,
            'first_name' => 'Лира',
            'username' => 'lyra_dreamer',
        ], JSON_THROW_ON_ERROR);

        $params = [
            'auth_date' => (string) now()->timestamp,
            'query_id' => 'AAG_test_query_id',
            'user' => $userPayload,
        ];
        ksort($params);

        $dataCheckString = collect($params)
            ->map(fn ($val, $key) => "{$key}={$val}")
            ->implode("\n");

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $hash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

        $initData = http_build_query(array_merge($params, ['hash' => $hash]));

        // Запрос без токена должен вернуть 401
        $this->getJson('/api/v1/city/state')->assertStatus(401);

        // Вход через Telegram создаёт игрока, город, ресурсы и сессию
        $loginResp = $this->postJson('/api/v1/auth/telegram', [
            'init_data' => $initData,
        ]);

        $loginResp->assertStatus(200)
            ->assertJsonPath('data.is_new_player', true)
            ->assertJsonPath('data.player.first_name', 'Лира');

        $token = (string) $loginResp->json('data.session_token');
        $this->assertNotEmpty($token);

        $player = Player::where('telegram_id', 987654321)->first();
        $this->assertNotNull($player);
        $this->assertDatabaseHas('cities', ['player_id' => $player->id, 'name' => 'Город Снов']);
        $this->assertDatabaseHas('player_resources', ['player_id' => $player->id, 'resource_type' => 'fog']);

        // Защищённый эндпоинт доступен с токеном
        $this->withToken($token)
            ->getJson('/api/v1/city/state')
            ->assertStatus(200)
            ->assertJsonPath('data.city.name', 'Город Снов');

        // Logout отзывает сессию, после чего токен перестаёт работать (401)
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('data.revoked', true);

        $this->withToken($token)
            ->getJson('/api/v1/city/state')
            ->assertStatus(401);
    }
}
