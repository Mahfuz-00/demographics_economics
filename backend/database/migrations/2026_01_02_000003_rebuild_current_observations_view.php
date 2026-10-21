<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `SELECT o.*` is expanded when a view is created, so the columns added in
     * 2026_01_02_000001 are not visible through `v_current_observations` until it is
     * rebuilt. CREATE OR REPLACE cannot help here: the new columns sit in the middle
     * of the table, which Postgres reads as renaming existing view columns. Drop and
     * recreate instead.
     */
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_current_observations');

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

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_current_observations');

        DB::statement(<<<'SQL'
            CREATE VIEW v_current_observations AS
            SELECT o.id, o.uuid, o.entity_id, o.indicator_id, o.period_type, o.period_key,
                   o.period_year, o.period_quarter, o.period_month, o.period_start, o.period_end,
                   o.value, o.value_text, o.unit, o.status, o.is_estimate, o.is_projection,
                   o.is_actual, o.missing_reason, o.raw_value, o.source_id, o.source_dataset_id,
                   o.source_snapshot_id, o.source_record_ref, o.source_url, o.reference_note,
                   o.published_date, o.retrieved_at, o.revision, o.content_hash, o.quality_score,
                   o.notes, o.import_batch_id, o.created_by, o.created_at, o.updated_at
            FROM observations o
            WHERE NOT EXISTS (
                SELECT 1
                FROM observation_supersessions s
                WHERE s.superseded_id = o.id
            )
        SQL);
    }
};
