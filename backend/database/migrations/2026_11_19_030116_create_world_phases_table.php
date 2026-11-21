<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_phases', function (Blueprint $table) {
            $table->id();
            $table->string('phase_name', 32);
            $table->decimal('multiplier_fog', 10, 6);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestamps();

            $table->index(['starts_at', 'ends_at']);
        });

        // Добавляем строкое ограничение на уровне PostgreSQL: фазы не должны пересекаться по времени
        DB::statement('
            ALTER TABLE world_phases 
            ADD CONSTRAINT world_phases_no_overlap 
            EXCLUDE USING gist (
                tstzrange(starts_at, ends_at, \'[)\') WITH &&
            );
        ');

        DB::statement('ALTER TABLE world_phases ADD CONSTRAINT chk_phase_time CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE world_phases ADD CONSTRAINT chk_phase_multiplier CHECK (multiplier_fog >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('world_phases');
    }
};
