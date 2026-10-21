<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_datasets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('sources')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('access_method', [
                'api_json', 'csv_bulk_zip', 'csv_single', 'html_scrape', 'wikitext', 'manual_upload',
            ])->default('api_json');
            $table->enum('auth_type', ['none', 'api_key', 'oauth'])->default('none');
            $table->string('credential_env_var')->nullable();
            $table->string('endpoint_url')->nullable();
            $table->string('documentation_url')->nullable();
            $table->enum('update_frequency', [
                'daily', 'weekly', 'monthly', 'quarterly', 'semiannual', 'annual', 'adhoc',
            ])->default('monthly');
            $table->unsignedSmallInteger('expected_latency_days')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('adapter_key')->nullable();
            $table->jsonb('config')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
            $table->index('update_frequency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_datasets');
    }
};
