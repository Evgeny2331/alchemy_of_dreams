<?php

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Models\PlayerSession;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SessionAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $this->extractToken($request);

        if ($plainToken === null || $plainToken === '') {
            return $this->unauthorized('UNAUTHENTICATED', 'Unauthorized: missing session token.');
        }

        $tokenHash = hash('sha256', $plainToken);
        $now = CarbonImmutable::now('UTC');

        /** @var PlayerSession|null $session */
        $session = PlayerSession::query()
            ->with('player')
            ->where('token_hash', $tokenHash)
            ->first();

        if ($session === null || $session->player === null) {
            return $this->unauthorized('UNAUTHENTICATED', 'Unauthorized: invalid session token.');
        }

        if ($session->revoked_at !== null) {
            return $this->unauthorized('SESSION_REVOKED', 'Unauthorized: session has been revoked.');
        }

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            return $this->unauthorized('SESSION_EXPIRED', 'Unauthorized: session has expired.');
        }

        $session->forceFill([
            'last_used_at' => $now,
        ])->save();

        $request->attributes->set('player', $session->player);
        $request->attributes->set('player_session', $session);
        $request->setUserResolver(static fn () => $session->player);

        return $next($request);
    }

    private function unauthorized(string $code, string $message): Response
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
            ],
        ], 401);
    }

    private function extractToken(Request $request): ?string
    {
        $bearer = $request->bearerToken();
        if (is_string($bearer) && $bearer !== '') {
            return trim($bearer);
        }

        $headerToken = $request->header('X-Session-Token');
        if (is_string($headerToken) && trim($headerToken) !== '') {
            return trim($headerToken);
        }

        return null;
    }
}
