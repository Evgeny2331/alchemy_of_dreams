<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('buildings')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->index(['city_id', 'status'], 'idx_buildings_city_status');
            });
        }

        if (Schema::hasTable('expeditions')) {
            Schema::table('expeditions', function (Blueprint $table) {
                $table->index(['city_id', 'status'], 'idx_expeditions_city_status');
                $table->index(['status', 'ends_at'], 'idx_expeditions_status_ends_at');
            });
        }

        if (Schema::hasTable('residents')) {
            Schema::table('residents', function (Blueprint $table) {
                $table->index(['city_id', 'archetype'], 'idx_residents_city_archetype');
                $table->index(['city_id', 'status'], 'idx_residents_city_status');
            });
        }

        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('buildings')) {
            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_building_hard_delete() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'buildings cannot be physically deleted; use status = ''demolished'' instead';
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::statement('DROP TRIGGER IF EXISTS trg_buildings_prevent_delete ON buildings');
            DB::statement(<<<'SQL'
CREATE TRIGGER trg_buildings_prevent_delete
BEFORE DELETE ON buildings
FOR EACH ROW EXECUTE FUNCTION prevent_building_hard_delete();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('buildings')) {
            DB::statement('DROP TRIGGER IF EXISTS trg_buildings_prevent_delete ON buildings');
            DB::statement('DROP FUNCTION IF EXISTS prevent_building_hard_delete()');
        }

        if (Schema::hasTable('residents')) {
            Schema::table('residents', function (Blueprint $table) {
                $table->dropIndex('idx_residents_city_archetype');
                $table->dropIndex('idx_residents_city_status');
            });
        }

        if (Schema::hasTable('expeditions')) {
            Schema::table('expeditions', function (Blueprint $table) {
                $table->dropIndex('idx_expeditions_city_status');
                $table->dropIndex('idx_expeditions_status_ends_at');
            });
        }

        if (Schema::hasTable('buildings')) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->dropIndex('idx_buildings_city_status');
            });
        }
    }
};
