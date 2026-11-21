<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Economy\Services\EconomyService;
use Tests\TestCase;

class EconomyAndIdempotencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // Принудительно настраиваем соединение прямо в рантайме теста
        config(['database.connections.pgsql.database' => 'alchemy_of_dreams_test']);
        
        // Накатываем миграции
        $this->artisan('migrate:fresh', [
            '--database' => 'pgsql',
            '--force' => true,
        ]);
    }

    public function test_resource_mutation_and_ledger_recording(): void
    {
        $player = Player::create([
            'telegram_id' => 999888777,
            'first_name' => 'TestPlayer',
        ]);

        $player->resources()->create([
            'resource_type' => 'fog',
            'balance' => '100.000000',
            'updated_at' => now(),
        ]);

        $economyService = app(EconomyService::class);
        $idempotencyKey = 'test-uuid-key-001';

        $newBalance = $economyService->mutateBalance(
            playerId: $player->id,
            resourceType: 'fog',
            amount: '-30.000000',
            operationType: 'test_operation',
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals('70.000000', $newBalance);

        $repeatedBalance = $economyService->mutateBalance(
            playerId: $player->id,
            resourceType: 'fog',
            amount: '-30.000000',
            operationType: 'test_operation',
            idempotencyKey: $idempotencyKey
        );

        $this->assertEquals('70.000000', $repeatedBalance);
    }
}
