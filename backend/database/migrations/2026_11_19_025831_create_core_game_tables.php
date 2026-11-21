<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Включаем расширение btree_gist для работы с диапазонами и фазами мира без пересечений
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist;');

        // 2. Города игроков (основной город игрока)
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->unique()->constrained('players')->cascadeOnDelete();
            $table->string('name', 128);
            $table->timestampTz('economy_calculated_at');
            $table->timestamps();
        });

        // 3. Каталог зданий
        Schema::create('building_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('building_key')->unique();
            $table->string('category', 32);
            $table->string('display_name', 128);
            $table->boolean('is_unique')->default(false);
            $table->boolean('is_story')->default(false);
            $table->boolean('supports_upgrade')->default(true);
            $table->smallInteger('footprint_width');
            $table->smallInteger('footprint_height');
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->default('{}');
            $table->timestamps();
        });

        // 4. Параметры уровней зданий
        Schema::create('building_level_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_catalog_id')->constrained('building_catalog')->cascadeOnDelete();
            $table->smallInteger('level');
            $table->decimal('fog_per_hour', 20, 6)->default(0);
            $table->decimal('storage_capacity', 20, 6)->default(0);
            $table->integer('build_time_seconds')->default(0);
            $table->jsonb('config')->default('{}');

            $table->unique(['building_catalog_id', 'level']);
        });
        DB::statement('ALTER TABLE building_level_configs ADD CONSTRAINT chk_blc_level CHECK (level BETWEEN 1 AND 3)');
        DB::statement('ALTER TABLE building_level_configs ADD CONSTRAINT chk_blc_fog CHECK (fog_per_hour >= 0)');
        DB::statement('ALTER TABLE building_level_configs ADD CONSTRAINT chk_blc_storage CHECK (storage_capacity >= 0)');
        DB::statement('ALTER TABLE building_level_configs ADD CONSTRAINT chk_blc_time CHECK (build_time_seconds >= 0)');

        // 5. Острова города
        Schema::create('islands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->smallInteger('island_index');
            $table->boolean('is_unlocked')->default(false);
            $table->timestamps();

            $table->unique(['city_id', 'island_index']);
            $table->unique(['id', 'city_id']); // Составной уникальный ключ для безопасных FK зданий
        });
        DB::statement('ALTER TABLE islands ADD CONSTRAINT chk_island_index CHECK (island_index >= 0)');

        // 6. Экземпляры зданий
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->unsignedBigInteger('island_id');
            $table->foreignId('building_catalog_id')->constrained('building_catalog');
            $table->string('building_type', 64);
            $table->smallInteger('level');
            $table->integer('position_x');
            $table->integer('position_y');
            $table->string('status', 16);
            $table->timestampTz('built_at')->nullable();
            $table->timestampTz('demolished_at')->nullable();
            $table->timestamps();

            // Составной внешний ключ к островам для строгой привязки к городу
            $table->foreign(['island_id', 'city_id'])->references(['id', 'city_id'])->on('islands')->cascadeOnDelete();
            $table->unique(['id', 'city_id']); // Составной ключ для привязки жителей
        });
        DB::statement('ALTER TABLE buildings ADD CONSTRAINT chk_building_level CHECK (level BETWEEN 1 AND 3)');
        DB::statement("ALTER TABLE buildings ADD CONSTRAINT chk_building_status CHECK (status IN ('active','building','demolished'))");
        DB::statement("ALTER TABLE buildings ADD CONSTRAINT chk_building_demolished CHECK ((status = 'demolished') = (demolished_at IS NOT NULL))");

        // 7. Жители города
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('archetype', 16);
            $table->string('name', 128);
            $table->smallInteger('trust_level')->default(0);
            $table->decimal('energy', 10, 2);
            $table->string('status', 16);
            $table->unsignedBigInteger('assigned_building_id')->nullable();
            $table->timestamps();

            $table->foreign(['assigned_building_id', 'city_id'])->references(['id', 'city_id'])->on('buildings')->nullOnDelete();
        });
        DB::statement("ALTER TABLE residents ADD CONSTRAINT chk_resident_archetype CHECK (archetype IN ('dream','phobia','memory'))");
        DB::statement('ALTER TABLE residents ADD CONSTRAINT chk_resident_trust CHECK (trust_level >= 0)');
        DB::statement('ALTER TABLE residents ADD CONSTRAINT chk_resident_energy CHECK (energy >= 0)');
        DB::statement("ALTER TABLE residents ADD CONSTRAINT chk_resident_status CHECK (status IN ('available','assigned','expedition','resting'))");

        // 8. Экспедиции
        Schema::create('expeditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->string('expedition_type', 16);
            $table->string('status', 16);
            $table->timestampTz('started_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->jsonb('reward_payload')->nullable();
            $table->bigInteger('outcome_seed')->nullable();
            $table->string('balance_version', 32);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE expeditions ADD CONSTRAINT chk_expedition_type CHECK (expedition_type IN ('surface','deep'))");
        DB::statement("ALTER TABLE expeditions ADD CONSTRAINT chk_expedition_status CHECK (status IN ('active','completed','claimed','cancelled'))");
        DB::statement('ALTER TABLE expeditions ADD CONSTRAINT chk_expedition_time CHECK (ends_at > started_at)');

        // 9. Участники экспедиций
        Schema::create('expedition_residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expedition_id')->constrained('expeditions')->cascadeOnDelete();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->decimal('energy_spent', 10, 2);
            $table->timestamps();

            $table->unique(['expedition_id', 'resident_id']);
        });
        DB::statement('ALTER TABLE expedition_residents ADD CONSTRAINT chk_exp_res_energy CHECK (energy_spent >= 0)');

        // 10. Рецепты и компоненты
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->string('recipe_key')->unique();
            $table->string('category', 16);
            $table->string('result_type', 32);
            $table->string('result_key', 64);
            $table->jsonb('metadata')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        DB::statement("ALTER TABLE recipes ADD CONSTRAINT chk_recipe_category CHECK (category IN ('critical','functional','decorative','secret'))");

        Schema::create('recipe_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('component_type', 32);
            $table->string('component_key', 64);
            $table->decimal('quantity', 20, 6)->default(1);

            $table->unique(['recipe_id', 'component_type', 'component_key']);
        });
        DB::statement('ALTER TABLE recipe_components ADD CONSTRAINT chk_recipe_comp_qty CHECK (quantity > 0)');

        Schema::create('player_recipe_archive', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->timestampTz('unlocked_at');

            $table->unique(['player_id', 'recipe_id']);
        });

        // 11. Идемпотентность и Outbox
        Schema::create('idempotency_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('operation_type', 64);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_payload')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['player_id', 'idempotency_key']);
        });

        Schema::create('transaction_outbox', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 128);
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('published_at')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_outbox');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('player_recipe_archive');
        Schema::dropIfExists('recipe_components');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('expedition_residents');
        Schema::dropIfExists('expeditions');
        Schema::dropIfExists('residents');
        Schema::dropIfExists('buildings');
        Schema::dropIfExists('islands');
        Schema::dropIfExists('building_level_configs');
        Schema::dropIfExists('building_catalog');
        Schema::dropIfExists('cities');
    }
};
