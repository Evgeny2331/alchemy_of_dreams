<?php

namespace App\Modules\Economy\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OutboxService
{
    /**
     * Обрабатывает пачку неопубликованных событий из transaction_outbox.
     *
     * @param  callable(object): void  $handler
     * @param  array<string, mixed>  $options  ['limit' => int, 'max_attempts' => int]
     * @return array{processed: int, failed: int, skipped: int}
     */
    public function processBatch(
        callable $handler,
        array $options = [],
        ?CarbonInterface $now = null
    ): array {
        $limit = max(1, (int) ($options['limit'] ?? 50));
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 5));
        $timestamp = CarbonImmutable::instance($now ?? CarbonImmutable::now('UTC'))->utc();

        $processed = 0;
        $failed = 0;
        $skipped = 0;

        $events = DB::transaction(function () use ($limit, $maxAttempts, $timestamp): array {
            $rows = DB::select(
                'SELECT * FROM transaction_outbox
                 WHERE published_at IS NULL AND attempts < ?
                 ORDER BY occurred_at ASC, id ASC
                 LIMIT ?
                 FOR UPDATE SKIP LOCKED',
                [$maxAttempts, $limit]
            );

            foreach ($rows as $row) {
                DB::table('transaction_outbox')
                    ->where('id', $row->id)
                    ->update([
                        'attempts' => ((int) $row->attempts) + 1,
                        'updated_at' => $timestamp,
                    ]);
            }

            return $rows;
        });

        /** @var array<string, bool> $seenEventIds */
        $seenEventIds = [];

        foreach ($events as $row) {
            $eventId = (string) $row->event_id;

            if (isset($seenEventIds[$eventId])) {
                DB::table('transaction_outbox')
                    ->where('id', $row->id)
                    ->update([
                        'published_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                $skipped++;
                continue;
            }

            $seenEventIds[$eventId] = true;

            $decodedPayload = is_string($row->payload)
                ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR)
                : (array) $row->payload;

            $eventObject = (object) [
                'id' => (int) $row->id,
                'event_id' => $eventId,
                'event_type' => (string) $row->event_type,
                'payload' => $decodedPayload,
                'occurred_at' => (string) $row->occurred_at,
                'attempts' => ((int) $row->attempts) + 1,
            ];

            try {
                $handler($eventObject);

                DB::table('transaction_outbox')
                    ->where('id', $row->id)
                    ->update([
                        'published_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);

                $processed++;
            } catch (Throwable $e) {
                Log::warning('Outbox event delivery failed.', [
                    'outbox_id' => (int) $row->id,
                    'event_id' => $eventId,
                    'event_type' => (string) $row->event_type,
                    'attempts' => $eventObject->attempts,
                    'error' => $e->getMessage(),
                ]);

                DB::table('transaction_outbox')
                    ->where('id', $row->id)
                    ->update([
                        'updated_at' => $timestamp,
                    ]);

                $failed++;
            }
        }

        return [
            'processed' => $processed,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }
}
