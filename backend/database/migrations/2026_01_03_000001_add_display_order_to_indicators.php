<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit column order for tabular views.
     *
     * The indicator catalogue was previously ordered by category, which is right for
     * browsing but wrong for the country table: the source spreadsheet has its own
     * sequence (peak population, present population, decline, migration, fertility,
     * births, deaths, age, life expectancy, per capita) and the table should read the
     * same way.
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->unsignedSmallInteger('display_order')->default(1000)->after('decimals');
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropIndex(['display_order']);
            $table->dropColumn('display_order');
        });
    }
};
