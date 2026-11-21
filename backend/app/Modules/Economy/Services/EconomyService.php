<?php

namespace App\Modules\Economy\Services;

use App\Modules\Auth\Models\Player;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class EconomyService
{
    public const ALLOWED_RESOURCES = [
        'fog',
        'silence',
        'memories',
        'metaphors',
        'nightmare_shards',
    ];

    public function assertValidResourceType(string $resourceType): void
    {
        if (!in_array($resourceType, self::ALLOWED_RESOURCES, true)) {
            throw new InvalidArgumentException("Unsupported resource type: {$resourceType}");
        }
    }

    /**
     * Атомарно изменяет баланс игрока с поддержкой идемпотентности по (player_id, idempotency_key)
     * и создает неизменяемую запись в resource_ledgers.
     */
    public function mutateBalance(
        int $playerId,
        string $resourceType,
        string $amount,
        string $operationType,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?CarbonImmutable $now = null
    ): string {
        $this->assertValidResourceType($resourceType);

        $timestamp = ($now ?? CarbonImmutable::now('UTC'))->utc();
        $amountFloat = (float) $amount;
        $formattedDelta = number_format(round($amountFloat, 6), 6, '.', '');

        $requestHash = hash('sha256', json_encode([
            'player_id' => $playerId,
            'resource_type' => $resourceType,
            'amount' => $formattedDelta,
            'operation_type' => $operationType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $playerId,
            $resourceType,
            $formattedDelta,
            $amountFloat,
            $operationType,
            $idempotencyKey,
            $referenceType,
            $referenceId,
            $requestHash,
            $timestamp
        ): string {
            $existingIdem = DB::table('idempotency_records')
                ->where('player_id', $playerId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingIdem !== null) {
                if ($existingIdem->request_hash !== $requestHash) {
                    throw new InvalidArgumentException('Idempotency key reused with different request payload.');
                }

                $decoded = json_decode((string) $existingIdem->response_payload, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['balance'])) {
                    return (string) $decoded['balance'];
                }
            }

            $resource = DB::table('player_resources')
                ->where('player_id', $playerId)
                ->where('resource_type', $resourceType)
                ->lockForUpdate()
                ->first();

            $currentBalance = $resource !== null ? (float) $resource->balance : 0.0;

            if (abs($amountFloat) < 1e-9) {
                $newBalanceFormatted = number_format(max(0.0, $currentBalance), 6, '.', '');
            } else {
                $newBalance = round($currentBalance + (float) $formattedDelta, 6);

                if ($newBalance < -1e-9) {
                    throw new RuntimeException("Insufficient {$resourceType} balance (required: " . abs((float) $formattedDelta) . ", available: {$currentBalance}).");
                }

                $newBalanceFormatted = number_format(max(0.0, $newBalance), 6, '.', '');

                if ($resource !== null) {
                    DB::table('player_resources')
                        ->where('id', $resource->id)
                        ->update([
                            'balance' => $newBalanceFormatted,
                            'updated_at' => $timestamp,
                        ]);
                } else {
                    DB::table('player_resources')->insert([
                        'player_id' => $playerId,
                        'resource_type' => $resourceType,
                        'balance' => $newBalanceFormatted,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                $ledgerPayload = [
                    'player_id' => $playerId,
                    'resource_type' => $resourceType,
                    'amount' => $formattedDelta,
                    'operation_type' => $operationType,
                    'idempotency_key' => $idempotencyKey,
                    'created_at' => $timestamp,
                ];

                $columns = DB::getSchemaBuilder()->getColumnListing('resource_ledgers');
                if (in_array('reference_type', $columns, true)) {
                    $ledgerPayload['reference_type'] = $referenceType;
                }
                if (in_array('reference_id', $columns, true)) {
                    $ledgerPayload['reference_id'] = $referenceId;
                }
                if (in_array('transaction_id', $columns, true)) {
                    $ledgerPayload['transaction_id'] = (string) Str::uuid();
                }
                if (in_array('updated_at', $columns, true)) {
                    $ledgerPayload['updated_at'] = $timestamp;
                }

                DB::table('resource_ledgers')->insert($ledgerPayload);
            }

            if ($existingIdem === null) {
                DB::table('idempotency_records')->insert([
                    'player_id' => $playerId,
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'operation_type' => $operationType,
                    'response_status' => 200,
                    'response_payload' => json_encode(['balance' => $newBalanceFormatted], JSON_THROW_ON_ERROR),
                    'completed_at' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            }

            return $newBalanceFormatted;
        });
    }

    /**
     * Обертка над mutateBalance для передачи модели Player.
     */
    public function applyResourceDelta(
        Player $player,
        string $resourceType,
        string $signedAmount,
        string $operationType,
        string $idempotencyKey,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?CarbonImmutable $now = null
    ): string {
        return $this->mutateBalance(
            playerId: (int) $player->id,
            resourceType: $resourceType,
            amount: $signedAmount,
            operationType: $operationType,
            idempotencyKey: $idempotencyKey,
            referenceType: $referenceType,
            referenceId: $referenceId,
            now: $now
        );
    }
}
