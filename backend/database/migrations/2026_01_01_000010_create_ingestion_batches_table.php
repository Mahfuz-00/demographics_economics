<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_dataset_id')->nullable()->constrained('source_datasets')->nullOnDelete();
            $table->foreignId('fetch_run_id')->nullable()->constrained('source_fetch_runs')->nullOnDelete();
            $table->string('kind');
            $table->enum('status', ['running', 'success', 'partial', 'failed'])->default('running');
            $table->string('file_name')->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->unsignedInteger('rows_seen')->default(0);
            $table->unsignedInteger('observations_created')->default(0);
            $table->unsignedInteger('observations_unchanged')->default(0);
            $table->unsignedInteger('observations_superseded')->default(0);
            $table->unsignedInteger('quarantined')->default(0);
            $table->unsignedInteger('conflicts')->default(0);
            $table->jsonb('report')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batches');
    }
};
