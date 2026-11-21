<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\City\Models\City;
use App\Modules\Economy\Services\OfflineEconomyService;
use App\Modules\Economy\Services\OutboxService;
use App\Modules\WorldPhase\Services\WorldPhaseService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OutboxProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_processes_events_retries_on_failure_and_marks_published(): void
    {
        $player = Player::create(['telegram_id' => 5001, 'first_name' => 'Publisher']);
        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');

        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Outbox Citadel',
            'economy_calculated_at' => $t0,
        ]);

        $phaseService = new WorldPhaseService();
        $syncService = new OfflineEconomyService($phaseService);
        $syncService->syncFogProduction($player, $city, '50.000000', 'outbox-idem-1', $t0->addHour());

        $this->assertEquals(1, DB::table('transaction_outbox')->whereNull('published_at')->count());

        $outbox = new OutboxService();

        // 1-я попытка: обработчик выбрасывает исключение — событие остаётся непубликованным, attempts = 1
        $firstRun = $outbox->processBatch(function (): void {
            throw new RuntimeException('Temporary broker error');
        }, ['limit' => 10, 'max_attempts' => 3], $t0->addMinutes(61));

        $this->assertEquals(0, $firstRun['processed']);
        $this->assertEquals(1, $firstRun['failed']);

        $rowAfterFail = DB::table('transaction_outbox')->first();
        $this->assertNull($rowAfterFail->published_at);
        $this->assertEquals(1, $rowAfterFail->attempts);

        // 2-я попытка: обработчик отрабатывает штатно — published_at проставляется, attempts = 2
        $handledEvents = [];
        $secondRun = $outbox->processBatch(function (object $event) use (&$handledEvents): void {
            $handledEvents[] = $event->event_id;
        }, ['limit' => 10, 'max_attempts' => 3], $t0->addMinutes(62));

        $this->assertEquals(1, $secondRun['processed']);
        $this->assertEquals(0, $secondRun['failed']);
        $this->assertCount(1, $handledEvents);

        $rowAfterSuccess = DB::table('transaction_outbox')->first();
        $this->assertNotNull($rowAfterSuccess->published_at);
        $this->assertEquals(2, $rowAfterSuccess->attempts);

        // Повторный запуск батча не должен забирать уже опубликованное событие
        $thirdRun = $outbox->processBatch(function () use (&$handledEvents): void {
            $handledEvents[] = 'unexpected';
        }, ['limit' => 10, 'max_attempts' => 3], $t0->addMinutes(63));

        $this->assertEquals(0, $thirdRun['processed']);
        $this->assertCount(1, $handledEvents);
    }
}
