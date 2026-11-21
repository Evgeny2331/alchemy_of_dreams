<?php

namespace App\Modules\Economy\Console;

use App\Modules\Economy\Services\OutboxService;
use Illuminate\Console\Command;

class ProcessOutboxCommand extends Command
{
    protected $signature = 'outbox:process
                            {--limit=50 : Maximum number of outbox events to process in one batch}
                            {--max-attempts=5 : Maximum delivery attempts per event}';

    protected $description = 'Process unpublished events from transaction_outbox';

    public function handle(OutboxService $outboxService): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $maxAttempts = max(1, (int) $this->option('max-attempts'));

        $summary = $outboxService->processBatch(
            function (object $event): void {
                $this->line(sprintf(
                    '[%s] %s (attempt #%d)',
                    $event->event_id,
                    $event->event_type,
                    $event->attempts
                ));
            },
            [
                'limit' => $limit,
                'max_attempts' => $maxAttempts,
            ]
        );

        $this->info(sprintf(
            'Outbox batch completed: processed=%d, failed=%d, skipped=%d',
            $summary['processed'],
            $summary['failed'],
            $summary['skipped']
        ));

        return self::SUCCESS;
    }
}
