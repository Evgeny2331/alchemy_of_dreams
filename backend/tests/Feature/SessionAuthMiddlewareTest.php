<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionAuthMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['player.session'])
            ->get('/api/v1/_test/session-check', function (Request $request) {
                /** @var Player $player */
                $player = $request->attributes->get('player');

                return response()->json([
                    'player_id' => $player->id,
                    'telegram_id' => $player->telegram_id,
                ]);
            });
    }

    public function test_middleware_rejects_missing_or_expired_or_revoked_tokens_and_accepts_valid_token(): void
    {
        $this->getJson('/api/v1/_test/session-check')->assertStatus(401);

        $player = Player::create([
            'telegram_id' => 91001,
            'first_name' => 'SessionKeeper',
        ]);

        // Просроченная сессия
        $expiredPlain = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $expiredPlain),
            'expires_at' => now()->subMinute(),
            'last_used_at' => now()->subHour(),
        ]);

        $this->withToken($expiredPlain)
            ->getJson('/api/v1/_test/session-check')
            ->assertStatus(401);

        // Отозванная сессия
        $revokedPlain = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $revokedPlain),
            'expires_at' => now()->addDay(),
            'revoked_at' => now()->subMinute(),
            'last_used_at' => now()->subHour(),
        ]);

        $this->withToken($revokedPlain)
            ->getJson('/api/v1/_test/session-check')
            ->assertStatus(401);

        // Валидная сессия
        $validPlain = Str::random(64);
        $validSession = PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $validPlain),
            'expires_at' => now()->addDay(),
            'last_used_at' => now()->subHour(),
        ]);

        $response = $this->withToken($validPlain)
            ->getJson('/api/v1/_test/session-check');

        $response->assertStatus(200)
            ->assertJsonPath('player_id', $player->id)
            ->assertJsonPath('telegram_id', 91001);

        $validSession->refresh();
        $this->assertNotNull($validSession->last_used_at);
    }
}
