<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_statistics_offices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('office_name');
            $table->string('website_url')->nullable();
            $table->string('data_portal_url')->nullable();
            $table->string('api_documentation_url')->nullable();
            $table->boolean('has_api')->default(false);
            $table->jsonb('preferred_indicators')->nullable();
            $table->text('access_notes')->nullable();
            $table->boolean('is_primary')->default(true);
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'office_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_statistics_offices');
    }
};
