<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('resident_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained('residents')->cascadeOnDelete();
            $table->string('request_type', 64);
            $table->jsonb('cost_payload');
            $table->jsonb('reward_payload');
            $table->string('status', 16);
            $table->timestampTz('available_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at');

            $table->index(['resident_id', 'available_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resident_requests');
    }
};
