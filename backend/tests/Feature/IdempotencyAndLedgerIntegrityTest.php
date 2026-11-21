<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Economy\Services\EconomyService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class IdempotencyAndLedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotent_retry_conflict_and_insufficient_balance_do_not_corrupt_ledger(): void
    {
        $service = app(EconomyService::class);
        $player = Player::create(['telegram_id' => 7601, 'first_name' => 'LedgerGuard']);

        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '50.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 01:00:00', 'UTC');
        $key = 'idem-charge-fog-001';

        // Первое списание 20.000000 -> баланс 30.000000
        $bal1 = $service->mutateBalance($player->id, 'fog', '-20.000000', 'building_construct', $key, 'city', 1, $t0);
        $this->assertSame('30.000000', $bal1);

        // Точный повтор с тем же ключом не списывает повторно и не создаёт вторую запись в ledger
        $bal2 = $service->mutateBalance($player->id, 'fog', '-20.000000', 'building_construct', $key, 'city', 1, $t0);
        $this->assertSame('30.000000', $bal2);
        $this->assertSame(1, DB::table('resource_ledgers')->where('player_id', $player->id)->count());

        // Использование того же ключа с другой суммой должно выбросить исключение конфликта параметров
        $conflictThrown = false;
        try {
            $service->mutateBalance($player->id, 'fog', '-10.000000', 'building_construct', $key, 'city', 1, $t0);
        } catch (InvalidArgumentException) {
            $conflictThrown = true;
        }
        $this->assertTrue($conflictThrown);

        // Попытка списать больше остатка (35.000000 при балансе 30.000000) должна быть отклонена без изменения баланса
        $insufficientThrown = false;
        try {
            $service->mutateBalance($player->id, 'fog', '-35.000000', 'building_construct', 'idem-overdraft-002', 'city', 1, $t0);
        } catch (RuntimeException) {
            $insufficientThrown = true;
        }
        $this->assertTrue($insufficientThrown);

        $currentBalance = (string) DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');

        $this->assertSame('30.000000', $currentBalance);
        $this->assertSame(1, DB::table('resource_ledgers')->where('player_id', $player->id)->count());
    }
}
