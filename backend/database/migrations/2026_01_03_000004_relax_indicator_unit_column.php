<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The `unit` column was an enum, which meant every new unit (currency in billions of
     * euros, for instance) needed a schema change to a check constraint. Units are
     * application-level semantics, not structural, so the column becomes a plain string.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE indicators DROP CONSTRAINT IF EXISTS indicators_unit_check');
        DB::statement('ALTER TABLE indicators ALTER COLUMN unit TYPE varchar(32)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE indicators ALTER COLUMN unit TYPE varchar(32)');
    }
};
