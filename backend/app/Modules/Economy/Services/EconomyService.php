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

    public function normalizeDecimal(string|int $value, int $scale = 6): string
    {
        $raw = trim((string) $value);
        if ($raw === '' || !preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $raw)) {
            throw new InvalidArgumentException("Invalid decimal value: {$value}");
        }

        $negative = str_starts_with($raw, '-');
        $unsigned = ltrim($raw, '+-');
        if ($unsigned === '') {
            $unsigned = '0';
        }

        $parts = explode('.', $unsigned, 2);
        $intPart = ltrim($parts[0], '0');
        if ($intPart === '') {
            $intPart = '0';
        }
        $fracPart = $parts[1] ?? '';
        $fracPadded = str_pad($fracPart, $scale + 1, '0', STR_PAD_RIGHT);
        $keepFrac = substr($fracPadded, 0, $scale);
        $roundDigit = (int) substr($fracPadded, $scale, 1);

        $digits = ($intPart . $keepFrac);
        if ($roundDigit >= 5) {
            $digits = $this->incrementDigits($digits);
        }

        if ($scale === 0) {
            $formatted = ltrim($digits, '0');
            return $formatted === '' ? '0' : ($negative && $formatted !== '0' ? '-' . $formatted : $formatted);
        }

        $totalLen = strlen($digits);
        if ($totalLen <= $scale) {
            $normInt = '0';
            $normFrac = str_pad($digits, $scale, '0', STR_PAD_LEFT);
        } else {
            $splitPos = $totalLen - $scale;
            $normInt = ltrim(substr($digits, 0, $splitPos), '0');
            if ($normInt === '') {
                $normInt = '0';
            }
            $normFrac = substr($digits, $splitPos);
        }

        $result = $normInt . '.' . $normFrac;
        $zero = '0.' . str_repeat('0', $scale);
        if ($result === $zero) {
            return $zero;
        }

        return $negative ? '-' . $result : $result;
    }

    private function incrementDigits(string $digits): string
    {
        $chars = str_split($digits);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '9') {
                $chars[$i] = (string) ((int) $chars[$i] + 1);
                return implode('', $chars);
            }
            $chars[$i] = '0';
        }
        return '1' . implode('', $chars);
    }

    private function addScaled(string $a, string $b, int $scale = 6): string
    {
        $na = $this->normalizeDecimal($a, $scale);
        $nb = $this->normalizeDecimal($b, $scale);
        $negA = str_starts_with($na, '-');
        $negB = str_starts_with($nb, '-');
        $absA = $this->toScaledInt($negA ? substr($na, 1) : $na, $scale);
        $absB = $this->toScaledInt($negB ? substr($nb, 1) : $nb, $scale);
        $signedA = $negA ? -$absA : $absA;
        $signedB = $negB ? -$absB : $absB;
        return $this->fromScaledInt($signedA + $signedB, $scale);
    }

    private function compareScaled(string $a, string $b, int $scale = 6): int
    {
        $na = $this->normalizeDecimal($a, $scale);
        $nb = $this->normalizeDecimal($b, $scale);
        $negA = str_starts_with($na, '-');
        $negB = str_starts_with($nb, '-');
        $va = $negA ? -$this->toScaledInt(substr($na, 1), $scale) : $this->toScaledInt($na, $scale);
        $vb = $negB ? -$this->toScaledInt(substr($nb, 1), $scale) : $this->toScaledInt($nb, $scale);
        return $va <=> $vb;
    }

    private function toScaledInt(string $unsignedNormalized, int $scale): int
    {
        [$intPart, $fracPart] = array_pad(explode('.', $unsignedNormalized, 2), 2, '');
        return (int) ($intPart . str_pad($fracPart, $scale, '0', STR_PAD_RIGHT));
    }

    private function fromScaledInt(int $scaled, int $scale): string
    {
        $negative = $scaled < 0;
        $abs = abs($scaled);
        $factor = 10 ** $scale;
        $intPart = intdiv($abs, $factor);
        $fracPart = str_pad((string) ($abs % $factor), $scale, '0', STR_PAD_LEFT);
        $formatted = $intPart . '.' . $fracPart;
        $zero = '0.' . str_repeat('0', $scale);
        if ($formatted === $zero) {
            return $zero;
        }
        return $negative ? '-' . $formatted : $formatted;
    }

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
        $formattedDelta = $this->normalizeDecimal($amount, 6);
        $zero = '0.000000';

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
            $zero,
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
                    return $this->normalizeDecimal((string) $decoded['balance'], 6);
                }
            }

            $resource = DB::table('player_resources')
                ->where('player_id', $playerId)
                ->where('resource_type', $resourceType)
                ->lockForUpdate()
                ->first();

            $currentBalance = $resource !== null ? $this->normalizeDecimal((string) $resource->balance, 6) : $zero;

            if ($this->compareScaled($formattedDelta, $zero, 6) === 0) {
                $newBalanceFormatted = $this->compareScaled($currentBalance, $zero, 6) < 0 ? $zero : $currentBalance;
            } else {
                $newBalance = $this->addScaled($currentBalance, $formattedDelta, 6);

                if ($this->compareScaled($newBalance, $zero, 6) < 0) {
                    $required = ltrim($formattedDelta, '-');
                    throw new RuntimeException("Insufficient {$resourceType} balance (required: {$required}, available: {$currentBalance}).");
                }

                $newBalanceFormatted = $newBalance;

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
