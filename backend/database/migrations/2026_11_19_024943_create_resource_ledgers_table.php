<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('resource_type');
            $table->decimal('amount', 18, 6);
            $table->string('operation_type');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_ledgers');
    }
};
