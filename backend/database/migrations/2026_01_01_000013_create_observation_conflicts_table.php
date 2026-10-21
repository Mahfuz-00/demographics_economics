<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('observation_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();
            $table->string('period_key', 12);
            $table->enum('period_type', ['annual', 'quarterly', 'monthly', 'point'])->default('annual');
            $table->smallInteger('period_year');
            $table->foreignId('primary_observation_id')->constrained('observations')->cascadeOnDelete();
            $table->foreignId('conflicting_observation_id')->constrained('observations')->cascadeOnDelete();
            $table->decimal('value_delta', 28, 10)->nullable();
            $table->decimal('pct_delta', 12, 4)->nullable();
            $table->enum('significance', ['minor', 'notable', 'major'])->default('minor');
            $table->enum('resolution_status', [
                'open', 'auto_resolved_by_priority', 'reviewed_kept_primary',
                'reviewed_changed_primary', 'dismissed',
            ])->default('open');
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['primary_observation_id', 'conflicting_observation_id'], 'observation_conflicts_pair_unique');
            $table->index(['entity_id', 'indicator_id', 'period_key'], 'observation_conflicts_series_idx');
            $table->index('resolution_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observation_conflicts');
    }
};
