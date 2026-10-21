<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW v_current_observations AS
            SELECT o.*
            FROM observations o
            WHERE NOT EXISTS (
                SELECT 1
                FROM observation_supersessions s
                WHERE s.superseded_id = o.id
            )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_current_observations');
    }
};
