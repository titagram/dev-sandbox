<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canonical_graph_projections', function (Blueprint $table) {
            $table->string('active_graph_version', 64)->nullable()->after('graph_version');
        });
        DB::table('canonical_graph_projections')
            ->whereNull('active_graph_version')
            ->update(['active_graph_version' => DB::raw('graph_version')]);

        Schema::create('canonical_graph_projection_attempts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('projection_id')->constrained('canonical_graph_projections')->cascadeOnDelete();
            $table->string('candidate_graph_version', 64)->unique();
            $table->string('status', 32);
            $table->unsignedBigInteger('node_count')->nullable();
            $table->unsignedBigInteger('relationship_count')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['projection_id', 'status'], 'canonical_graph_attempt_projection_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_graph_projection_attempts');
        Schema::table('canonical_graph_projections', function (Blueprint $table) {
            $table->dropColumn('active_graph_version');
        });
    }
};
