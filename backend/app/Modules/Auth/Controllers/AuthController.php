<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\Auth\Services\AuthService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $authService) {}

    public function telegram(Request $request): JsonResponse
    {
        $request->validate([
            'init_data' => 'required|string',
        ]);

        $result = $this->authService->authenticateTelegram($request->input('init_data'));

        return response()->json([
            'data' => $result,
            'meta' => [
                'request_id' => (string) Str::uuid(),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var PlayerSession|null $session */
        $session = $request->attributes->get('player_session');

        if ($session !== null && $session->revoked_at === null) {
            $session->forceFill([
                'revoked_at' => CarbonImmutable::now('UTC'),
            ])->save();
        }

        return response()->json([
            'data' => [
                'revoked' => true,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
            ],
        ]);
    }
}
