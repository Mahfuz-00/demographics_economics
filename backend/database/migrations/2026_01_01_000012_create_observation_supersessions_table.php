<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_supersessions', function (Blueprint $table) {
            $table->foreignId('superseded_id')->primary()->constrained('observations')->cascadeOnDelete();
            $table->foreignId('superseded_by_id')->constrained('observations')->cascadeOnDelete();
            $table->enum('reason', ['revision', 'source_priority', 'correction', 'manual'])->default('revision');
            $table->timestamp('created_at')->useCurrent();

            $table->index('superseded_by_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_supersessions');
    }
};
