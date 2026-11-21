<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ALLOWED_RESOURCES_SQL = "'fog','silence','memories','metaphors','nightmare_shards'";

    public function up(): void
    {
        Schema::table('resource_ledgers', function (Blueprint $table) {
            if (!Schema::hasColumn('resource_ledgers', 'reference_type')) {
                $table->string('reference_type', 64)->nullable()->after('operation_type');
            }
            if (!Schema::hasColumn('resource_ledgers', 'reference_id')) {
                $table->unsignedBigInteger('reference_id')->nullable()->after('reference_type');
            }
            if (!Schema::hasColumn('resource_ledgers', 'transaction_id')) {
                $table->uuid('transaction_id')->nullable()->after('idempotency_key');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE player_resources ALTER COLUMN balance TYPE NUMERIC(20,6)');
            DB::statement('ALTER TABLE player_resources ALTER COLUMN balance SET DEFAULT 0');
            DB::statement('ALTER TABLE player_resources DROP CONSTRAINT IF EXISTS chk_player_resources_balance_non_negative');
            DB::statement('ALTER TABLE player_resources ADD CONSTRAINT chk_player_resources_balance_non_negative CHECK (balance >= 0)');
            DB::statement('ALTER TABLE player_resources DROP CONSTRAINT IF EXISTS chk_player_resources_type');
            DB::statement("ALTER TABLE player_resources ADD CONSTRAINT chk_player_resources_type CHECK (resource_type IN (" . self::ALLOWED_RESOURCES_SQL . "))");

            DB::statement('ALTER TABLE resource_ledgers ALTER COLUMN amount TYPE NUMERIC(20,6)');
            DB::statement('ALTER TABLE resource_ledgers DROP CONSTRAINT IF EXISTS resource_ledgers_idempotency_key_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS resource_ledgers_player_idem_res_unique ON resource_ledgers (player_id, idempotency_key, resource_type)');
            DB::statement('ALTER TABLE resource_ledgers DROP CONSTRAINT IF EXISTS chk_resource_ledgers_amount_nonzero');
            DB::statement('ALTER TABLE resource_ledgers ADD CONSTRAINT chk_resource_ledgers_amount_nonzero CHECK (amount <> 0)');
            DB::statement('ALTER TABLE resource_ledgers DROP CONSTRAINT IF EXISTS chk_resource_ledgers_type');
            DB::statement("ALTER TABLE resource_ledgers ADD CONSTRAINT chk_resource_ledgers_type CHECK (resource_type IN (" . self::ALLOWED_RESOURCES_SQL . "))");

            DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_resource_ledger_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'resource_ledgers is immutable and cannot be updated or deleted';
END;
$$ LANGUAGE plpgsql;
SQL);

            DB::statement('DROP TRIGGER IF EXISTS trg_resource_ledgers_immutable ON resource_ledgers');
            DB::statement(<<<'SQL'
CREATE TRIGGER trg_resource_ledgers_immutable
BEFORE UPDATE OR DELETE ON resource_ledgers
FOR EACH ROW EXECUTE FUNCTION prevent_resource_ledger_mutation();
SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_resource_ledgers_immutable ON resource_ledgers');
            DB::statement('DROP FUNCTION IF EXISTS prevent_resource_ledger_mutation()');
            DB::statement('ALTER TABLE resource_ledgers DROP CONSTRAINT IF EXISTS chk_resource_ledgers_type');
            DB::statement('ALTER TABLE resource_ledgers DROP CONSTRAINT IF EXISTS chk_resource_ledgers_amount_nonzero');
            DB::statement('DROP INDEX IF EXISTS resource_ledgers_player_idem_res_unique');

            DB::statement('ALTER TABLE player_resources DROP CONSTRAINT IF EXISTS chk_player_resources_type');
            DB::statement('ALTER TABLE player_resources DROP CONSTRAINT IF EXISTS chk_player_resources_balance_non_negative');
        }
    }
};
