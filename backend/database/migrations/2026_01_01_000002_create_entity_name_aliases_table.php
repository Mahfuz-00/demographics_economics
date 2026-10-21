<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_name_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
            $table->string('alias');
            $table->string('normalized_alias');
            $table->string('source_hint')->nullable();
            $table->decimal('confidence', 4, 3)->default(1.000);
            $table->timestamps();

            $table->unique(['normalized_alias', 'entity_id']);
            $table->index('normalized_alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_name_aliases');
    }
};
