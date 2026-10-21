<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            /*
             * Set when the source states a value but no reference period for it, and the
             * value has been dated to the compilation year of the document it came from.
             * Flagged so it is never presented as if the source had stated the year.
             */
            $table->boolean('period_is_assumed')->default(false)->after('missing_reason');

            /*
             * Set when a human has explicitly curated this value. Curated values are
             * served ahead of any source's, because a person looking at the actual
             * statistics outranks an automated ingest — but every other value is still
             * retained, so curation never destroys evidence.
             */
            $table->boolean('is_curated')->default(false)->after('period_is_assumed');

            $table->index('is_curated');
        });
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropIndex(['is_curated']);
            $table->dropColumn(['period_is_assumed', 'is_curated']);
        });
    }
};
