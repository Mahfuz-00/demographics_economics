<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_fetch_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_dataset_id')->nullable()->constrained('source_datasets')->nullOnDelete();
            $table->enum('status', ['queued', 'running', 'success', 'partial', 'failed', 'skipped'])->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->unsignedInteger('records_seen')->default(0);
            $table->unsignedInteger('records_new')->default(0);
            $table->unsignedInteger('records_revised')->default(0);
            $table->unsignedInteger('records_unchanged')->default(0);
            $table->unsignedInteger('records_rejected')->default(0);
            $table->string('error_class')->nullable();
            $table->text('error_message')->nullable();
            $table->enum('triggered_by', ['schedule', 'manual', 'cli', 'api'])->default('cli');
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->longText('log')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_fetch_runs');
    }
};
