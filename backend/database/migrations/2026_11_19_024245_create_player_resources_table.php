<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->string('resource_type');
            $table->decimal('balance', 18, 6)->default(0);
            $table->timestamps();
            
            $table->unique(['player_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_resources');
    }
};
