<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicators', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('category_id')->nullable()->constrained('indicator_categories')->nullOnDelete();
            $table->enum('module', ['demographics', 'economy', 'debt', 'wealth'])->default('demographics');
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->text('description')->nullable();
            $table->text('definition')->nullable();
            $table->enum('unit', [
                'persons', 'percent', 'per_1000', 'years', 'children_per_woman',
                'currency_usd', 'currency_usd_bn', 'count', 'year', 'ratio', 'index', 'text',
            ])->default('count');
            $table->enum('value_kind', ['level', 'rate', 'ratio', 'index', 'count', 'currency', 'year', 'text'])
                ->default('level');
            $table->enum('built_in_frequency', ['annual', 'quarterly', 'monthly', 'irregular'])->default('annual');
            $table->boolean('supports_disaggregation')->default(false);
            $table->boolean('is_stock')->default(false);
            $table->boolean('is_per_capita')->default(false);
            $table->boolean('is_sensitive')->default(false);
            $table->boolean('is_derived')->default(false);
            $table->text('derivation_note')->nullable();
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->boolean('higher_is_better')->nullable();
            $table->enum('aggregation_method', ['sum', 'mean', 'weighted_mean', 'none'])->default('none');
            $table->smallInteger('typical_min_year')->nullable();
            $table->smallInteger('typical_max_year')->nullable();
            $table->timestamps();

            $table->index('module');
            $table->index('category_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicators');
    }
};
