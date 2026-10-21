<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('iso_alpha2', 2)->nullable();
            $table->char('iso_alpha3', 3)->nullable();
            $table->char('iso_numeric', 3)->nullable();
            $table->string('name');
            $table->string('official_name')->nullable();
            $table->string('slug')->unique();
            $table->enum('entity_type', [
                'country', 'dependency', 'territory', 'region', 'subregion', 'aggregate', 'group',
            ])->default('country');
            $table->foreignId('sovereignty_entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->foreignId('parent_entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->string('continent')->nullable();
            $table->string('subregion')->nullable();
            $table->string('csv_label')->nullable();
            $table->boolean('is_aggregate')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique('iso_alpha3');
            $table->index('iso_alpha2');
            $table->index('entity_type');
            $table->index('continent');
            $table->index('is_aggregate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
    }
};
