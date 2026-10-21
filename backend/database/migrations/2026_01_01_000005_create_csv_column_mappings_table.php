<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Describes how the manually maintained spreadsheet maps onto indicators.
     *
     * The source file is not one table: it is several small tables laid side by
     * side, each with its own key column. `sub_table_order` gives the left-to-right
     * order in which those tables appear in the header (which is how the splitter
     * tells the several `Country` columns apart); `source_column_index` is the
     * 0-based position of a column *within its sub-table*.
     */
    public function up(): void
    {
        Schema::create('csv_column_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('sub_table');
            $table->unsignedSmallInteger('sub_table_order');
            $table->unsignedSmallInteger('source_column_index');
            $table->string('csv_column')->nullable();
            $table->foreignId('indicator_id')->nullable()->constrained('indicators')->nullOnDelete();
            $table->enum('value_role', ['entity', 'value', 'value_year', 'year', 'label', 'note', 'skip'])
                ->default('value');
            $table->unsignedSmallInteger('year_source_column_index')->nullable();
            $table->string('transform')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sub_table', 'source_column_index']);
            $table->index('sub_table');
            $table->index('sub_table_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_column_mappings');
    }
};
