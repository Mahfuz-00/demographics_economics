<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a value that was calculated from other observations rather than read from a
     * source — crude rates from births, deaths and population, for example. Derived
     * values are always labelled, always ranked below reported ones, and never replace
     * a figure a source actually published.
     */
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->boolean('is_derived')->default(false)->after('is_curated');
            $table->index('is_derived');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropIndex(['is_derived']);
            $table->dropColumn('is_derived');
        });
    }
};
