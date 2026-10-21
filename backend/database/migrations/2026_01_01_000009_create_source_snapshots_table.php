<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_dataset_id')->nullable()->constrained('source_datasets')->nullOnDelete();
            $table->foreignId('fetch_run_id')->nullable()->constrained('source_fetch_runs')->nullOnDelete();
            $table->string('url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->char('content_hash', 64);
            $table->string('storage_path')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->boolean('is_parsed')->default(false);
            $table->text('parse_error')->nullable();
            $table->timestamps();

            $table->unique(['source_dataset_id', 'content_hash']);
            $table->index('content_hash');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_snapshots');
    }
};
