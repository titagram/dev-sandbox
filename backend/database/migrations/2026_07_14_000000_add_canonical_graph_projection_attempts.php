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
            $table->string('owner_token', 64);
            $table->ulid('expected_ready_projection_id')->nullable();
            $table->string('expected_active_graph_version', 64)->nullable();
            $table->string('status', 32);
            $table->string('publication_stage', 32)->default('building');
            $table->unsignedBigInteger('node_count')->nullable();
            $table->unsignedBigInteger('relationship_count')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('heartbeat_at');
            $table->timestamp('lease_expires_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['projection_id', 'status'], 'canonical_graph_attempt_projection_status_idx');
            $table->index(['status', 'lease_expires_at'], 'canonical_graph_attempt_lease_idx');
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
