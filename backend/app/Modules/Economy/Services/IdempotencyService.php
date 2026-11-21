<?php

namespace App\Modules\Economy\Services;

use App\Modules\Auth\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IdempotencyService
{
    public function resolveKey(Request $request): ?string
    {
        $headerKey = $request->header('Idempotency-Key');
        if (is_string($headerKey) && trim($headerKey) !== '') {
            return trim($headerKey);
        }

        $bodyKey = $request->input('idempotency_key');
        if (is_string($bodyKey) && trim($bodyKey) !== '') {
            return trim($bodyKey);
        }

        return null;
    }

    /**
     * Выполняет мутирующее действие идемпотентно, если передан Idempotency-Key / idempotency_key.
     *
     * @param  callable(): array{status: int, payload: array<string, mixed>}  $callback
     */
    public function execute(
        Player $player,
        ?string $idempotencyKey,
        string $operationType,
        array $fingerprintData,
        callable $callback,
        ?CarbonImmutable $now = null
    ): JsonResponse {
        if ($idempotencyKey === null) {
            $result = $callback();

            return response()->json($result['payload'], $result['status']);
        }

        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $requestHash = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $player,
            $idempotencyKey,
            $operationType,
            $requestHash,
            $callback,
            $timestamp
        ): JsonResponse {
            $existing = DB::table('idempotency_records')
                ->where('player_id', $player->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_hash !== $requestHash) {
                    return response()->json([
                        'error' => [
                            'code' => 'IDEMPOTENCY_KEY_REUSED',
                            'message' => 'Idempotency key reused with different request payload.',
                            'details' => [],
                        ],
                        'meta' => [
                            'request_id' => (string) Str::uuid(),
                        ],
                    ], 409);
                }

                $decoded = json_decode((string) $existing->response_payload, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data'])) {
                    $decoded['data']['idempotent_replay'] = true;
                }

                return response()->json($decoded, (int) $existing->response_status);
            }

            $result = $callback();
            $status = (int) $result['status'];
            $payload = $result['payload'];

            DB::table('idempotency_records')->insert([
                'player_id' => $player->id,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'operation_type' => $operationType,
                'response_status' => $status,
                'response_payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'completed_at' => $timestamp,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            return response()->json($payload, $status);
        });
    }
}
