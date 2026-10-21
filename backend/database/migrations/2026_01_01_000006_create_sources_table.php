<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('publisher');
            $table->string('publisher_short')->nullable();
            $table->enum('source_type', [
                'national_statistics', 'un', 'world_bank', 'imf', 'oecd',
                'wikipedia', 'other_official', 'manual', 'other',
            ])->default('other');
            $table->unsignedTinyInteger('credibility_rank')->default(9);
            $table->string('homepage_url')->nullable();
            $table->string('license')->nullable();
            $table->string('license_url')->nullable();
            $table->text('terms_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('source_type');
            $table->index('credibility_rank');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
