<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Observations are immutable fact rows. A correction or a newer value from the
     * same source never UPDATEs an existing row: it inserts a new revision and links
     * the two rows through `observation_supersessions`. The "current" value of any
     * series point is therefore the observation with no supersession record.
     */
    public function up(): void
    {
        Schema::create('observations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->foreignId('indicator_id')->constrained('indicators')->cascadeOnDelete();

            $table->enum('period_type', ['annual', 'quarterly', 'monthly', 'point'])->default('annual');
            $table->string('period_key', 12);
            $table->smallInteger('period_year');
            $table->unsignedTinyInteger('period_quarter')->nullable();
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->decimal('value', 28, 10)->nullable();
            $table->text('value_text')->nullable();
            $table->string('unit');
            $table->enum('status', [
                'reported', 'preliminary', 'revised', 'estimated', 'projected',
                'modelled', 'manual_entry', 'missing', 'not_applicable',
            ])->default('reported');
            $table->boolean('is_estimate')->default(false);
            $table->boolean('is_projection')->default(false);
            $table->boolean('is_actual')->default(true);
            $table->enum('missing_reason', [
                'not_collected', 'not_published', 'suppressed', 'not_applicable', 'pending', 'unknown',
            ])->nullable();

            $table->string('raw_value')->nullable();

            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->foreignId('source_dataset_id')->nullable()->constrained('source_datasets')->nullOnDelete();
            $table->foreignId('source_snapshot_id')->nullable()->constrained('source_snapshots')->nullOnDelete();
            $table->string('source_record_ref')->nullable();
            $table->string('source_url')->nullable();
            $table->string('reference_note')->nullable();
            $table->date('published_date')->nullable();
            $table->timestamp('retrieved_at')->nullable();

            $table->unsignedSmallInteger('revision')->default(1);
            $table->char('content_hash', 64);
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('import_batch_id')->nullable()->constrained('ingestion_batches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('content_hash');
            $table->unique(
                ['entity_id', 'indicator_id', 'period_key', 'source_id', 'revision'],
                'observations_series_revision_unique'
            );
            $table->index(['entity_id', 'indicator_id', 'period_type', 'period_year'], 'observations_entity_indicator_period_idx');
            $table->index(['indicator_id', 'period_year'], 'observations_indicator_year_idx');
            $table->index(['indicator_id', 'period_key'], 'observations_indicator_period_idx');
            $table->index('source_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('observations');
    }
};
