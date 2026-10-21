<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Removes `v_current_observations`.
     *
     * The view was created with `SELECT o.*`, which PostgreSQL expands at creation
     * time. Every later column added to `observations` was therefore invisible through
     * it until the view was rebuilt — twice already — and a query against a stale view
     * fails with "column does not exist" rather than falling back.
     *
     * `Observation::scopeCurrent()` expresses exactly the same thing in the application
     * and cannot go stale, so the view is dropped rather than kept as a second, fragile
     * source of truth. The definition, for anyone writing raw SQL, is: an observation is
     * current when no row in `observation_supersessions` names it as superseded.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_current_observations');
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW v_current_observations AS
            SELECT o.*
            FROM observations o
            WHERE NOT EXISTS (
                SELECT 1
                FROM observation_supersessions s
                WHERE s.superseded_id = o.id
            )
        SQL);
    }
};
