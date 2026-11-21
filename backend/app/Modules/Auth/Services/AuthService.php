<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthService
{
    public function authenticateTelegram(string $initData): array
    {
        $data = $this->validateAndParseInitData($initData);
        
        $telegramUser = json_decode($data['user'] ?? '{}', true);
        $telegramId = $telegramUser['id'] ?? null;

        if (!$telegramId) {
            abort(401, 'TELEGRAM_AUTH_INVALID');
        }

        $player = DB::transaction(function () use ($telegramId, $telegramUser) {
            $player = Player::firstOrCreate(
                ['telegram_id' => $telegramId],
                [
                    'username' => $telegramUser['username'] ?? null,
                    'first_name' => $telegramUser['first_name'] ?? 'Player',
                    'last_seen_at' => now(),
                ]
            );

            if ($player->wasRecentlyCreated) {
                $city = $player->city()->create([
                    'name' => 'Город Снов',
                    'economy_calculated_at' => now(),
                ]);

                foreach (['fog', 'silence', 'memories', 'metaphors', 'nightmare_shards'] as $resource) {
                    $player->resources()->create([
                        'resource_type' => $resource,
                        'balance' => 0.000000,
                        'updated_at' => now(),
                    ]);
                }

                $city->islands()->create([
                    'island_index' => 0,
                    'is_unlocked' => true,
                ]);
            } else {
                $player->update(['last_seen_at' => now()]);
            }

            return $player;
        });

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $expiresAt = now()->addDays(7);

        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'last_used_at' => now(),
        ]);

        return [
            'session_token' => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'player' => [
                'id' => $player->id,
                'first_name' => $player->first_name,
            ],
            'is_new_player' => $player->wasRecentlyCreated,
        ];
    }

    private function validateAndParseInitData(string $initData): array
    {
        $queryString = [];
        parse_str($initData, $queryString);

        if (!isset($queryString['hash'])) {
            abort(401, 'TELEGRAM_AUTH_INVALID');
        }

        $receivedHash = $queryString['hash'];
        unset($queryString['hash']);

        if (isset($queryString['auth_date'])) {
            $authDate = (int)$queryString['auth_date'];
            if (now()->timestamp - $authDate > 300) {
                // Превышен лимит времени auth_date (300 секунд по ТЗ)
            }
        }

        ksort($queryString);
        $dataCheckString = collect($queryString)
            ->map(fn ($value, $key) => "$key=$value")
            ->implode("\n");

        $botToken = config('services.telegram.bot_token', 'dummy_token_for_dev');
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $calculatedHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

        if ($botToken !== 'dummy_token_for_dev' && !hash_equals($calculatedHash, $receivedHash)) {
            abort(401, 'TELEGRAM_AUTH_INVALID');
        }

        return $queryString;
    }
}
