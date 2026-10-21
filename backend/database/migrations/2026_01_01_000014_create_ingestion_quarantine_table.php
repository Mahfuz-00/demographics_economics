<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_quarantine', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->nullable()->constrained('ingestion_batches')->nullOnDelete();
            $table->foreignId('source_dataset_id')->nullable()->constrained('source_datasets')->nullOnDelete();
            $table->string('sub_table')->nullable();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('reason_code');
            $table->text('reason_detail')->nullable();
            $table->jsonb('raw_row')->nullable();
            $table->boolean('resolved')->default(false);
            $table->foreignId('resolved_observation_id')->nullable()->constrained('observations')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('reason_code');
            $table->index('resolved');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_quarantine');
    }
};
