<?php

namespace App\Modules\Alchemy\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Alchemy\Models\PlayerRecipeArchive;
use App\Modules\Alchemy\Services\AlchemyService;
use App\Modules\Auth\Models\Player;
use App\Modules\Economy\Services\IdempotencyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class AlchemyController extends Controller
{
    public function __construct(
        private readonly AlchemyService $alchemyService,
        private readonly IdempotencyService $idempotencyService
    ) {}

    public function archive(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $entries = PlayerRecipeArchive::query()
            ->with('recipe')
            ->where('player_id', $sessionPlayer->id)
            ->orderByDesc('unlocked_at')
            ->get();

        return response()->json([
            'data' => [
                'recipes' => $entries,
            ],
            'meta' => ['request_id' => (string) Str::uuid()],
        ]);
    }

    public function transmute(Request $request): JsonResponse
    {
        /** @var Player|null $sessionPlayer */
        $sessionPlayer = $request->attributes->get('player');
        if ($sessionPlayer === null) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validated = $request->validate([
            'player_id' => ['nullable', 'integer', 'exists:players,id'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_type' => ['required', 'string', 'max:32'],
            'components.*.component_key' => ['required', 'string', 'max:64'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
            'now' => ['nullable', 'date'],
        ]);

        if (isset($validated['player_id']) && (int) $validated['player_id'] !== (int) $sessionPlayer->id) {
            return response()->json(['message' => 'Forbidden: player mismatch.'], 403);
        }

        $now = isset($validated['now'])
            ? CarbonImmutable::parse((string) $validated['now'])->utc()
            : null;

        $idemKey = $this->idempotencyService->resolveKey($request);

        return $this->idempotencyService->execute(
            $sessionPlayer,
            $idemKey,
            'alchemy_transmute',
            [
                'player_id' => $sessionPlayer->id,
                'components' => $validated['components'],
            ],
            function () use ($sessionPlayer, $validated, $now): array {
                try {
                    $result = $this->alchemyService->transmute(
                        $sessionPlayer,
                        $validated['components'],
                        $now
                    );
                } catch (InvalidArgumentException|RuntimeException $e) {
                    return [
                        'status' => 422,
                        'payload' => ['message' => $e->getMessage()],
                    ];
                }

                return [
                    'status' => 200,
                    'payload' => [
                        'data' => array_merge($result, ['idempotent_replay' => false]),
                        'meta' => ['request_id' => (string) Str::uuid()],
                    ],
                ];
            },
            $now
        );
    }
}
