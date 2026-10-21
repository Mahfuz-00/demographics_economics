<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Candidate sources found by web search when a scrape turns up nothing usable.
     *
     * Nothing found this way is recorded as data. Candidates are proposals for a human
     * to accept or reject, which is what keeps "search the internet for it" from
     * quietly turning into "trust whatever the internet said".
     */
    public function up(): void
    {
        Schema::create('source_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->cascadeOnDelete();
            $table->foreignId('indicator_id')->nullable()->constrained('indicators')->nullOnDelete();
            $table->string('query');
            $table->string('provider')->default('duckduckgo');
            $table->string('title')->nullable();
            $table->string('url');
            $table->text('snippet')->nullable();
            $table->string('host')->nullable();
            $table->unsignedTinyInteger('relevance_score')->nullable();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'duplicate'])->default('pending');
            $table->string('reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_id', 'url'], 'source_candidates_entity_url_unique');
            $table->index('status');
            $table->index('entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_candidates');
    }
};
