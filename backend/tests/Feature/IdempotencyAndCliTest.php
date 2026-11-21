<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\Player;
use App\Modules\Auth\Models\PlayerSession;
use App\Modules\City\Models\BuildingCatalog;
use App\Modules\City\Models\City;
use App\Modules\City\Models\Island;
use App\Modules\Residents\Models\Resident;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdempotencyAndCliTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotent_build_claim_replay_conflict_and_outbox_cli_command(): void
    {
        $player = Player::create(['telegram_id' => 6601, 'first_name' => 'IdemTester']);
        DB::table('player_resources')->insert([
            'player_id' => $player->id,
            'resource_type' => 'fog',
            'balance' => '500.000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plainToken = Str::random(64);
        PlayerSession::create([
            'player_id' => $player->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addDay(),
            'last_used_at' => now(),
        ]);

        $t0 = CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC');
        $city = City::create([
            'player_id' => $player->id,
            'name' => 'Idem City',
            'economy_calculated_at' => $t0,
        ]);
        $island = Island::create([
            'city_id' => $city->id,
            'island_index' => 0,
            'is_unlocked' => true,
        ]);

        BuildingCatalog::create([
            'building_key' => 'mist_harvester',
            'category' => 'functional',
            'display_name' => 'Сборщик Тумана',
            'is_unique' => false,
            'is_story' => false,
            'supports_upgrade' => true,
            'footprint_width' => 2,
            'footprint_height' => 2,
            'is_active' => true,
            'metadata' => [],
        ]);

        $headers = ['Idempotency-Key' => 'idem-build-001'];

        $first = $this->withToken($plainToken)
            ->withHeaders($headers)
            ->postJson('/api/v1/city/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'mist_harvester',
                'position_x' => 0,
                'position_y' => 0,
                'now' => $t0->toIso8601String(),
            ]);

        $first->assertStatus(201)
            ->assertJsonPath('data.idempotent_replay', false);

        // Повтор с тем же Idempotency-Key и тем же payload не списывает fog повторно
        $replay = $this->withToken($plainToken)
            ->withHeaders($headers)
            ->postJson('/api/v1/city/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'mist_harvester',
                'position_x' => 0,
                'position_y' => 0,
                'now' => $t0->toIso8601String(),
            ]);

        $replay->assertStatus(201)
            ->assertJsonPath('data.idempotent_replay', true);

        $fogBalance = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'fog')
            ->value('balance');
        $this->assertSame('400.000000', (string) $fogBalance);

        // Повтор с тем же Idempotency-Key, но другим payload -> 409 Conflict
        $conflict = $this->withToken($plainToken)
            ->withHeaders($headers)
            ->postJson('/api/v1/city/buildings', [
                'city_id' => $city->id,
                'island_id' => $island->id,
                'building_key' => 'mist_harvester',
                'position_x' => 4,
                'position_y' => 4,
                'now' => $t0->toIso8601String(),
            ]);

        $conflict->assertStatus(409);

        // Идемпотентный claim награды экспедиции
        $resident = Resident::create([
            'city_id' => $city->id,
            'archetype' => 'dream',
            'name' => 'Следопыт',
            'trust_level' => 1,
            'energy' => '5.00',
            'status' => 'available',
            'assigned_building_id' => null,
        ]);

        $startExp = $this->withToken($plainToken)->postJson('/api/v1/expeditions/start', [
            'city_id' => $city->id,
            'expedition_type' => 'surface',
            'resident_ids' => [$resident->id],
            'now' => $t0->toIso8601String(),
        ])->assertStatus(201);

        $expId = (int) $startExp->json('data.id');
        $claimKey = ['Idempotency-Key' => 'idem-claim-001'];

        $claim1 = $this->withToken($plainToken)
            ->withHeaders($claimKey)
            ->postJson("/api/v1/expeditions/{$expId}/claim", [
                'now' => $t0->addMinutes(10)->toIso8601String(),
            ])->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', false);

        $memoriesAfterFirst = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'memories')
            ->value('balance');

        $this->withToken($plainToken)
            ->withHeaders($claimKey)
            ->postJson("/api/v1/expeditions/{$expId}/claim", [
                'now' => $t0->addMinutes(10)->toIso8601String(),
            ])->assertStatus(200)
            ->assertJsonPath('data.idempotent_replay', true);

        $memoriesAfterReplay = DB::table('player_resources')
            ->where('player_id', $player->id)
            ->where('resource_type', 'memories')
            ->value('balance');
        $this->assertSame((string) $memoriesAfterFirst, (string) $memoriesAfterReplay);

        // Запуск CLI-команды outbox:process помечает события опубликованными
        $this->artisan('outbox:process', ['--limit' => 20])
            ->expectsOutputToContain('processed=2')
            ->assertExitCode(0);

        $unpublished = DB::table('transaction_outbox')->whereNull('published_at')->count();
        $this->assertSame(0, $unpublished);
    }
}
